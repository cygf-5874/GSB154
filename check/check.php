<?php

declare(strict_types=1);

/**
 * 固定验收入口：filelock 的「对外契约」。**别改这个文件。**
 *
 * 用法：
 *   php check/check.php                   跑全部 8 个场景，全过才退 0
 *   php check/check.php -list             列出全部场景
 *   php check/check.php --only fairness   只跑一组
 *
 * 五组共 8 个场景：
 *   basic    2 —— 共享共存 / 可重入与错误语义；
 *   blocking 2 —— 非阻塞不入队 / 释放后队首命中所授予的锁；
 *   fairness 2 —— 严格 FIFO / 共享成批但挡住后面的写者；
 *   upgrade  1 —— 独占到共享的原地原子降级（反向抛错）；
 *   snapshot 1 —— describe() 快照结构与确定性排序。
 *
 * 判据全部走注入的假后端：不碰真实文件系统、不 sleep、不读墙钟。
 * 失败不早退；每个场景用 try/catch(\Throwable) 兜住后继续。
 */

require __DIR__ . '/../autoload.php';

use Filelock\FileLock;
use Filelock\LockBackend;
use Filelock\LockException;

/** 内存里的确定性假后端：没有真实文件、没有真实等待。 */
final class FakeBackend implements LockBackend
{
    /** @var array<string, int> */
    private array $mode = [];

    /** @var array<string, array<int, string>> */
    private array $holders = [];

    /** @var array<string, array<int, array{ticket: int, owner: string, mode: int}>> */
    private array $queue = [];

    private int $ticketSeq = 0;

    public function lock(string $owner, string $name, int $mode): bool
    {
        $cur = $this->mode[$name] ?? null;

        if ($cur === null) {
            $this->mode[$name] = $mode;
            $this->holders[$name] = [$owner];

            return true;
        }

        if (in_array($owner, $this->holders[$name] ?? [], true)) {
            return true;
        }

        if ($cur === FileLock::SHARED && $mode === FileLock::SHARED) {
            $this->holders[$name][] = $owner;

            return true;
        }

        return false;
    }

    public function convert(string $owner, string $name, int $mode): bool
    {
        if (($this->mode[$name] ?? null) === null || !in_array($owner, $this->holders[$name] ?? [], true)) {
            return false;
        }

        if ($mode === FileLock::SHARED) {
            $this->mode[$name] = FileLock::SHARED;

            return true;
        }

        if (count($this->holders[$name]) === 1) {
            $this->mode[$name] = FileLock::EXCLUSIVE;

            return true;
        }

        return false;
    }

    public function unlock(string $owner, string $name): void
    {
        if (!isset($this->holders[$name])) {
            return;
        }

        $this->holders[$name] = array_values(array_filter(
            $this->holders[$name],
            static fn (string $o): bool => $o !== $owner
        ));

        if ($this->holders[$name] === []) {
            unset($this->holders[$name], $this->mode[$name]);
        }
    }

    public function mode(string $name): ?int
    {
        return $this->mode[$name] ?? null;
    }

    public function holders(string $name): array
    {
        $out = $this->holders[$name] ?? [];
        sort($out, SORT_STRING);

        return array_values($out);
    }

    public function enqueue(string $name, string $owner, int $mode): int
    {
        $ticket = ++$this->ticketSeq;
        $this->queue[$name][] = ['ticket' => $ticket, 'owner' => $owner, 'mode' => $mode];

        return $ticket;
    }

    public function waiters(string $name): array
    {
        $q = $this->queue[$name] ?? [];
        usort($q, static fn (array $a, array $b): int => $a['ticket'] <=> $b['ticket']);

        return array_values($q);
    }

    public function dequeue(string $name, int $ticket): ?array
    {
        $q = $this->queue[$name] ?? [];

        foreach ($q as $i => $entry) {
            if ($entry['ticket'] === $ticket) {
                unset($q[$i]);
                $this->queue[$name] = array_values($q);

                return $entry;
            }
        }

        return null;
    }

    public function names(): array
    {
        $names = [];
        foreach (array_keys($this->mode) as $n) {
            $names[$n] = true;
        }
        foreach (array_keys($this->queue) as $n) {
            $names[$n] = true;
        }

        $keys = array_keys($names);
        sort($keys, SORT_STRING);

        return $keys;
    }

    public function now(): int
    {
        return 0;
    }
}

final class CheckFailure extends \Exception
{
    public string $expected;

    public string $actual;

    public function __construct(string $message, string $expected = '-', string $actual = '-')
    {
        parent::__construct($message);
        $this->expected = $expected;
        $this->actual = $actual;
    }
}

function tk(string $name, string $why, callable $fn): array
{
    return ['name' => $name, 'why' => $why, 'fn' => $fn];
}

function export(mixed $v): string
{
    $s = var_export($v, true);
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;

    return strlen($s) > 320 ? substr($s, 0, 317) . '...' : $s;
}

/** 逐值严格比较（顺序相关）；失败时把期望 / 实际都 var_export 出来。 */
function expect_same(mixed $want, mixed $got, string $what): void
{
    if ($want !== $got) {
        throw new CheckFailure($what, export($want), export($got));
    }
}

function expect_true(bool $cond, string $what, string $expected, string $actual): void
{
    if (!$cond) {
        throw new CheckFailure($what, $expected, $actual);
    }
}

function throws_lock(string $what, callable $fn): void
{
    try {
        $fn();
    } catch (LockException $exc) {
        return;
    } catch (\Throwable $exc) {
        throw new CheckFailure(
            $what . ' 应抛 LockException',
            'LockException',
            get_class($exc) . ': ' . $exc->getMessage()
        );
    }

    throw new CheckFailure($what . ' 应抛 LockException', 'LockException', '正常返回');
}

/** 提取 held 里的 name 序列，便于比较。 */
function held_names(array $snap): array
{
    return array_map(static fn (array $h): string => $h['name'], $snap['held'] ?? []);
}

$groups = [];

// ---------------------------------------------------------------------------
// basic
// ---------------------------------------------------------------------------

$groups['basic'] = [
    tk('shared-coexist', '保证 1 / 2 / 3：两个读锁共存，写锁被挡在外面', static function (): void {
        $be = new FakeBackend();
        $a = new FileLock($be, 'a');
        $b = new FileLock($be, 'b');
        $c = new FileLock($be, 'c');

        expect_same(true, $a->acquire('r', FileLock::SHARED), 'a 拿共享锁');
        expect_same(true, $b->acquire('r', FileLock::SHARED), 'b 拿共享锁');
        expect_same(false, $c->acquire('r', FileLock::EXCLUSIVE, false), 'c 拿独占被挡');
        expect_same(['a', 'b'], $be->holders('r'), '两个读持有者');
        expect_same(FileLock::SHARED, $be->mode('r'), '资源模式为共享');
    }),
    tk('reentrant-and-errors', '保证 2 / 5 / 6：可重入与异常语义', static function (): void {
        $be = new FakeBackend();
        $a = new FileLock($be, 'a');

        expect_same(true, $a->acquire('r', FileLock::EXCLUSIVE), '第一次拿锁');
        expect_same(true, $a->acquire('r', FileLock::EXCLUSIVE), '第二次可重入');
        expect_same(['a'], $be->holders('r'), '可重入没有重复持有');

        $a->release('r');
        expect_same(['a'], $be->holders('r'), '释放一次仍持有（计数 2→1）');
        $a->release('r');
        expect_same([], $be->holders('r'), '释放两次后彻底释放');
        expect_same(null, $be->mode('r'), '资源空闲');

        throws_lock('未持有就 release', static fn () => $a->release('r'));
        throws_lock('非法模式', static fn () => $a->acquire('r', 7));
        throws_lock('未持有就 convert', static fn () => $a->convert('r', FileLock::SHARED));
    }),
];

// ---------------------------------------------------------------------------
// blocking
// ---------------------------------------------------------------------------

$groups['blocking'] = [
    tk('nonblocking-queue-free', '保证 3：非阻塞冲突不入队，阻塞冲突入队', static function (): void {
        $be = new FakeBackend();
        $a = new FileLock($be, 'a');
        $b = new FileLock($be, 'b');

        $a->acquire('r', FileLock::EXCLUSIVE);

        expect_same(false, $b->acquire('r', FileLock::EXCLUSIVE, false), '非阻塞失败');
        expect_same([], $be->waiters('r'), '非阻塞不得入队');

        expect_same(false, $b->acquire('r', FileLock::EXCLUSIVE, true), '阻塞调用立即返回 false');
        $waiters = $be->waiters('r');
        expect_same(1, count($waiters), '等待队列一项');
        expect_same('b', $waiters[0]['owner'], '队首是 b');
        expect_same(FileLock::EXCLUSIVE, $waiters[0]['mode'], '申请模式是独占');
    }),
    tk('adopt-after-release', '保证 3 / 4：被授予后再次 acquire 命中', static function (): void {
        $be = new FakeBackend();
        $a = new FileLock($be, 'a');
        $b = new FileLock($be, 'b');
        $c = new FileLock($be, 'c');

        $a->acquire('r', FileLock::EXCLUSIVE);
        expect_same(false, $b->acquire('r', FileLock::EXCLUSIVE, true), 'b 入队');
        expect_same(false, $c->acquire('r', FileLock::EXCLUSIVE, true), 'c 入队');

        $a->release('r');
        expect_same(['b'], $be->holders('r'), '释放后队首 b 被授予');

        expect_same(true, $b->acquire('r', FileLock::EXCLUSIVE, false), 'b 命中已授予的锁');
        expect_same(false, $c->acquire('r', FileLock::EXCLUSIVE, false), 'c 仍被挡');
        expect_same(1, count($be->waiters('r')), 'c 仍在排队');

        $b->release('r');
        expect_same(['c'], $be->holders('r'), 'b 释放后轮到 c');
    }),
];

// ---------------------------------------------------------------------------
// fairness
// ---------------------------------------------------------------------------

$groups['fairness'] = [
    tk('fifo-order', '保证 4：严格先进先出', static function (): void {
        $be = new FakeBackend();
        $a = new FileLock($be, 'a');
        $b = new FileLock($be, 'b');
        $c = new FileLock($be, 'c');

        $a->acquire('r', FileLock::EXCLUSIVE);
        $b->acquire('r', FileLock::EXCLUSIVE, true);
        $c->acquire('r', FileLock::EXCLUSIVE, true);

        $a->release('r');
        expect_same(['b'], $be->holders('r'), '先排队的 b 先获锁，而不是 c');
        expect_same(1, count($be->waiters('r')), 'c 还在排队');

        expect_same(true, $b->acquire('r', FileLock::EXCLUSIVE, false), 'b 命中所授予的锁');
        $b->release('r');
        expect_same(['c'], $be->holders('r'), 'b 释放后轮到 c');
    }),
    tk('shared-batch-blocks-writer', '保证 4：共享成批授予，但挡住后面的写者', static function (): void {
        $be = new FakeBackend();
        $a = new FileLock($be, 'a');
        $b = new FileLock($be, 'b');
        $c = new FileLock($be, 'c');
        $d = new FileLock($be, 'd');

        $a->acquire('r', FileLock::EXCLUSIVE);
        $b->acquire('r', FileLock::SHARED, true);
        $c->acquire('r', FileLock::SHARED, true);
        $d->acquire('r', FileLock::EXCLUSIVE, true);

        $a->release('r');
        expect_same(['b', 'c'], $be->holders('r'), '两个读同时放行');
        expect_same(['d'], array_map(
            static fn (array $w): string => $w['owner'],
            $be->waiters('r')
        ), '写者仍被挡在队列里');

        expect_same(true, $b->acquire('r', FileLock::SHARED, false), 'b 命中所授予的锁');
        expect_same(true, $c->acquire('r', FileLock::SHARED, false), 'c 命中所授予的锁');

        $b->release('r');
        expect_same(['c'], $be->holders('r'), '读锁互相不影响');
        expect_same(1, count($be->waiters('r')), '仍有写者在等');

        $c->release('r');
        expect_same(['d'], $be->holders('r'), '读锁全放后写者才拿到');
        expect_same([], $be->waiters('r'), '队列清空');
    }),
];

// ---------------------------------------------------------------------------
// upgrade
// ---------------------------------------------------------------------------

$groups['upgrade'] = [
    tk('downgrade-atomic', '保证 5：独占→共享原地原子降级；反向抛错', static function (): void {
        $be = new FakeBackend();
        $a = new FileLock($be, 'a');
        $b = new FileLock($be, 'b');

        $a->acquire('r', FileLock::EXCLUSIVE);
        expect_same(true, $a->convert('r', FileLock::SHARED), '降级成功');
        expect_same(['a'], $be->holders('r'), '降级不换持有者（原子）');
        expect_same(FileLock::SHARED, $be->mode('r'), '模式变为共享');
        expect_same([], $be->waiters('r'), '降级不产生等待');

        expect_same(true, $b->acquire('r', FileLock::SHARED, false), '降级后读锁可进');

        throws_lock('共享升独占', static fn () => $a->convert('r', FileLock::EXCLUSIVE));
        throws_lock('持有共享再 acquire 独占', static fn () => $a->acquire('r', FileLock::EXCLUSIVE));
    }),
];

// ---------------------------------------------------------------------------
// snapshot
// ---------------------------------------------------------------------------

$groups['snapshot'] = [
    tk('describe-snapshot', '保证 8 / 9：快照结构与确定性排序', static function (): void {
        $be = new FakeBackend();
        $a = new FileLock($be, 'a');
        $b = new FileLock($be, 'b');
        $c = new FileLock($be, 'c');
        $d = new FileLock($be, 'd');
        $e = new FileLock($be, 'e');

        $a->acquire('b-lock', FileLock::EXCLUSIVE);
        $b->acquire('a-lock', FileLock::EXCLUSIVE);
        $c->acquire('a-lock', FileLock::SHARED, true);
        $d->acquire('a-lock', FileLock::EXCLUSIVE, true);
        $e->acquire('b-lock', FileLock::EXCLUSIVE, true);

        $snap = $a->describe();

        expect_true(is_array($snap) && array_key_exists('held', $snap) && array_key_exists('waiting', $snap), '快照键', 'held + waiting', export($snap));

        expect_same(['a-lock', 'b-lock'], held_names($snap), 'held 按资源名字节序升序');
        expect_same(
            [
                ['name' => 'a-lock', 'mode' => FileLock::EXCLUSIVE, 'owners' => ['b']],
                ['name' => 'b-lock', 'mode' => FileLock::EXCLUSIVE, 'owners' => ['a']],
            ],
            $snap['held'],
            'held 结构与内容'
        );
        expect_same(
            [
                ['name' => 'a-lock', 'owner' => 'c', 'mode' => FileLock::SHARED, 'ticket' => 1],
                ['name' => 'a-lock', 'owner' => 'd', 'mode' => FileLock::EXCLUSIVE, 'ticket' => 2],
                ['name' => 'b-lock', 'owner' => 'e', 'mode' => FileLock::EXCLUSIVE, 'ticket' => 3],
            ],
            $snap['waiting'],
            'waiting 按 (资源名, 票号) 排序'
        );

        expect_same($snap, $a->describe(), '同一状态两次快照完全相同');
    }),
];

// ---------------------------------------------------------------------------
// 运行器
// ---------------------------------------------------------------------------

$flat = [];
foreach ($groups as $group => $entries) {
    foreach ($entries as $entry) {
        $flat[] = [$group, $entry['name'], $entry['why'], $entry['fn']];
    }
}

$doList = false;
$only = null;

for ($i = 1; $i < count($argv); $i++) {
    $arg = $argv[$i];

    if ($arg === '-list' || $arg === '--list') {
        $doList = true;
    } elseif ($arg === '--only' || $arg === '--group') {
        $i++;
        if ($i >= count($argv)) {
            fwrite(STDERR, "--only 需要一个组名（basic/blocking/fairness/upgrade/snapshot）\n");
            exit(2);
        }
        $only = [];
        foreach (explode(',', $argv[$i]) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $only[$part] = true;
            }
        }
    } elseif ($arg === '-h' || $arg === '--help') {
        echo "用法: php check/check.php [-list] [--only <组名>]\n";
        exit(0);
    } else {
        fwrite(STDERR, "未知参数: {$arg}\n");
        exit(2);
    }
}

if ($doList) {
    foreach ($flat as [$group, $name, $why, $_fn]) {
        printf("[%-8s] %-28s %s\n", $group, $name, $why);
    }
    exit(0);
}

$selected = [];
foreach ($flat as $entry) {
    if ($only === null || isset($only[$entry[0]])) {
        $selected[] = $entry;
    }
}

if ($selected === []) {
    fwrite(STDERR, "没有匹配的场景\n");
    exit(2);
}

$passed = 0;
$failed = 0;

foreach ($selected as [$group, $name, $_why, $fn]) {
    try {
        $fn();
    } catch (CheckFailure $exc) {
        $failed++;
        printf("FAIL %s/%s  期望=%s 实际=%s（%s）\n", $group, $name, $exc->expected, $exc->actual, $exc->getMessage());
        continue;
    } catch (\Throwable $exc) {
        $failed++;
        printf("FAIL %s/%s  期望=场景正常结束 实际=%s: %s\n", $group, $name, get_class($exc), $exc->getMessage());
        continue;
    }

    $passed++;
    printf("PASS %s/%s\n", $group, $name);
}

printf("结果：通过 %d/%d\n", $passed, count($selected));
exit($failed === 0 ? 0 : 1);