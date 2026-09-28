<?php

declare(strict_types=1);

/**
 * filelock 既有用例：12 个。**别改这个文件。**
 *
 * 用法： php tests/run.php     全绿 → 退出码 0，否则 1
 *
 * 只走 README 里公开的对外方法，不碰任何内部结构。
 * 注入的 `TestBackend` 是内存里的确定性后端，不碰真实文件系统。
 */

require __DIR__ . '/../autoload.php';

use Filelock\FileLock;
use Filelock\LockBackend;
use Filelock\LockException;

/** 内存里的确定性后端：只给既有用例用。 */
final class TestBackend implements LockBackend
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

$GLOBALS['t_total'] = 0;
$GLOBALS['t_pass'] = 0;

function tcase(string $name, callable $fn): void
{
    $GLOBALS['t_total']++;

    try {
        $fn();
    } catch (\Throwable $exc) {
        printf("FAIL %s —— %s: %s\n", $name, get_class($exc), $exc->getMessage());

        return;
    }

    $GLOBALS['t_pass']++;
}

function same(mixed $want, mixed $got, string $what): void
{
    if ($want !== $got) {
        throw new \RuntimeException(
            $what . ' 期望=' . var_export($want, true) . ' 实际=' . var_export($got, true)
        );
    }
}

function throws_lock(string $what, callable $fn): void
{
    try {
        $fn();
    } catch (LockException $exc) {
        return;
    } catch (\Throwable $exc) {
        throw new \RuntimeException(
            $what . ' 应抛 LockException，实际 ' . get_class($exc) . ': ' . $exc->getMessage()
        );
    }

    throw new \RuntimeException($what . ' 应抛 LockException，实际正常返回');
}

tcase('两个共享锁可共存', static function (): void {
    $be = new TestBackend();
    $a = new FileLock($be, 'a');
    $b = new FileLock($be, 'b');

    same(true, $a->acquire('r', FileLock::SHARED), 'a 拿共享锁');
    same(true, $b->acquire('r', FileLock::SHARED), 'b 拿共享锁');
    same(['a', 'b'], $be->holders('r'), '两个读持有者');
    same(FileLock::SHARED, $be->mode('r'), '模式为共享');
});

tcase('独占与共享互斥（非阻塞返回 false）', static function (): void {
    $be = new TestBackend();
    $a = new FileLock($be, 'a');
    $b = new FileLock($be, 'b');

    same(true, $a->acquire('r', FileLock::EXCLUSIVE), 'a 拿独占锁');
    same(false, $b->acquire('r', FileLock::SHARED, false), 'b 非阻塞拿共享应失败');
});

tcase('同一持有者同模式可重入', static function (): void {
    $be = new TestBackend();
    $a = new FileLock($be, 'a');

    same(true, $a->acquire('r', FileLock::EXCLUSIVE), '第一次');
    same(true, $a->acquire('r', FileLock::EXCLUSIVE), '第二次可重入');
    same(['a'], $be->holders('r'), '仍然只有一个持有者');
});

tcase('未持有就释放抛 LockException', static function (): void {
    $be = new TestBackend();
    $a = new FileLock($be, 'a');

    throws_lock('release 未持有', static fn () => $a->release('r'));
});

tcase('非法模式抛 LockException', static function (): void {
    $be = new TestBackend();
    $a = new FileLock($be, 'a');

    throws_lock('acquire 非法模式', static fn () => $a->acquire('r', 99));
});

tcase('非阻塞冲突不入队', static function (): void {
    $be = new TestBackend();
    $a = new FileLock($be, 'a');
    $b = new FileLock($be, 'b');

    $a->acquire('r', FileLock::EXCLUSIVE);
    same(false, $b->acquire('r', FileLock::EXCLUSIVE, false), '非阻塞失败');
    same([], $be->waiters('r'), '不得入队');
});

tcase('阻塞冲突入队并返回 false', static function (): void {
    $be = new TestBackend();
    $a = new FileLock($be, 'a');
    $b = new FileLock($be, 'b');

    $a->acquire('r', FileLock::EXCLUSIVE);
    same(false, $b->acquire('r', FileLock::EXCLUSIVE, true), '阻塞调用立即返回 false');
    $waiters = $be->waiters('r');
    same(1, count($waiters), '等待队列一项');
    same('b', $waiters[0]['owner'], '队首是 b');
});

tcase('释放后队首可再次 acquire 成功', static function (): void {
    $be = new TestBackend();
    $a = new FileLock($be, 'a');
    $b = new FileLock($be, 'b');

    $a->acquire('r', FileLock::EXCLUSIVE);
    $b->acquire('r', FileLock::EXCLUSIVE, true);
    $a->release('r');

    same(true, $b->acquire('r', FileLock::EXCLUSIVE, false), 'b 命中已授予的锁');
    same(['b'], $be->holders('r'), '持有者换成 b');
});

tcase('FIFO：先排队者先获锁', static function (): void {
    $be = new TestBackend();
    $a = new FileLock($be, 'a');
    $b = new FileLock($be, 'b');
    $c = new FileLock($be, 'c');

    $a->acquire('r', FileLock::EXCLUSIVE);
    $b->acquire('r', FileLock::EXCLUSIVE, true);
    $c->acquire('r', FileLock::EXCLUSIVE, true);
    $a->release('r');

    same(['b'], $be->holders('r'), '先排队的 b 先获锁');
});

tcase('持有独占可原地降级为共享', static function (): void {
    $be = new TestBackend();
    $a = new FileLock($be, 'a');

    $a->acquire('r', FileLock::EXCLUSIVE);
    same(true, $a->convert('r', FileLock::SHARED), '降级成功');
    same(FileLock::SHARED, $be->mode('r'), '模式变为共享');
    same(['a'], $be->holders('r'), '持有者不变（原子）');
});

tcase('反向转换抛 LockException', static function (): void {
    $be = new TestBackend();
    $a = new FileLock($be, 'a');

    $a->acquire('r', FileLock::SHARED);
    throws_lock('共享升独占', static fn () => $a->convert('r', FileLock::EXCLUSIVE));
});

tcase('describe 快照结构与排序', static function (): void {
    $be = new TestBackend();
    $a = new FileLock($be, 'a');
    $b = new FileLock($be, 'b');

    $a->acquire('b-lock', FileLock::EXCLUSIVE);
    $b->acquire('a-lock', FileLock::SHARED);

    $snap = $a->describe();
    same(['a-lock', 'b-lock'], array_map(
        static fn (array $h): string => $h['name'],
        $snap['held']
    ), 'held 按字节序升序');
    same([], $snap['waiting'], '无等待者');
});

printf("通过 %d/%d\n", $GLOBALS['t_pass'], $GLOBALS['t_total']);

exit($GLOBALS['t_pass'] === $GLOBALS['t_total'] ? 0 : 1);