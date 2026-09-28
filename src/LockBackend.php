<?php

declare(strict_types=1);

namespace Filelock;

/**
 * 可注入的锁后端：抽象真实的文件锁（`flock` 一类）。
 *
 * 由调用方注入。固定验收程序会注入一个**假后端**（内存里的确定性实现），
 * 因此实现**不得**触碰真实文件系统、不得 sleep、不得读墙钟，时间只能问 `now()`。
 *
 * 所有 `$mode` 取值都是 `FileLock::SHARED` 或 `FileLock::EXCLUSIVE`。
 */
interface LockBackend
{
    /**
     * 以 `$owner` 的身份尝试给 `$name` 加 `$mode` 锁，**不阻塞**：
     *   - 立即授予：返回 `true`；
     *   - 已被不兼容的持有者占用：返回 `false`（不等待、不入队）。
     * 同一 `$owner` 已经持有 `$name` 时返回 `true`（幂等）。
     */
    public function lock(string $owner, string $name, int $mode): bool;

    /**
     * 把 `$owner` 已持有的 `$name` **原地**改成 `$mode`（原子，不是「解锁再上锁」）。
     * 不可行返回 `false`。`$owner` 未持有 `$name` 时返回 `false`。
     */
    public function convert(string $owner, string $name, int $mode): bool;

    /** 释放 `$owner` 对 `$name` 的持有。 */
    public function unlock(string $owner, string $name): void;

    /** `$name` 当前的持有模式（无人持有返回 `null`）。 */
    public function mode(string $name): ?int;

    /** `$name` 当前的持有者 id 列表，按字节序升序（无人持有返回 `[]`）。 */
    public function holders(string $name): array;

    /**
     * 把 `$owner` 登记为 `$name` 的等待者（申请 `$mode`），返回一个**全局单调递增**的票号。
     * 票号就是公平排序键：票号小的先被服务。
     */
    public function enqueue(string $name, string $owner, int $mode): int;

    /**
     * `$name` 的等待队列，按票号升序。
     * 每个元素形如 `['ticket' => int, 'owner' => string, 'mode' => int]`。
     */
    public function waiters(string $name): array;

    /** 按票号把等待者移出队列并返回该元素；票号不存在返回 `null`。 */
    public function dequeue(string $name, int $ticket): ?array;

    /**
     * 已知的资源名（出现过持有者或出现过等待者的名字），按字节序升序。
     * 供 `FileLock::describe()` 构造确定性快照。
     */
    public function names(): array;

    /** 单调时钟刻度（整数）。不得读墙钟。 */
    public function now(): int;
}