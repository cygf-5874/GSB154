<?php

declare(strict_types=1);

namespace Filelock;

/**
 * 建议性文件锁的语义层。
 *
 * 对外契约见 README「对外契约」的 9 条。当前只有签名，方法体全抛错。
 */
final class FileLock
{
    /** 共享锁（读锁）。 */
    public const SHARED = 1;

    /** 独占锁（写锁）。 */
    public const EXCLUSIVE = 2;

    private LockBackend $backend;

    /** 本实例代表的持有者标识。 */
    private string $owner;

    public function __construct(LockBackend $backend, string $owner)
    {
        $this->backend = $backend;
        $this->owner = $owner;
    }

    /**
     * 申请 `$name` 上的 `$mode` 锁。
     *
     * `$blocking` 为 `false`（NON_BLOCKING）时冲突立即返回 `false` 且不入队；
     * 为 `true` 时把本实例登记进等待队列并返回 `false`（**不阻塞、不 sleep**），
     * 被公平唤醒后再调用本方法即会命中已授予的锁。
     */
    public function acquire(string $name, int $mode, bool $blocking = true): bool
    {
        throw new \LogicException('not implemented');
    }

    /** 释放 `$name`（可重入：需要与 `acquire` 次数成对）。未持有则抛 `LockException`。 */
    public function release(string $name): void
    {
        throw new \LogicException('not implemented');
    }

    /** 当前持有者把 `$name` 原地转成 `$mode`；只允许 `EXCLUSIVE → SHARED`。 */
    public function convert(string $name, int $mode): bool
    {
        throw new \LogicException('not implemented');
    }

    /**
     * 当前持有与等待队列的确定性快照。
     *
     * @return array{
     *     held: array<int, array{name: string, mode: int, owners: array<int, string>}>,
     *     waiting: array<int, array{name: string, owner: string, mode: int, ticket: int}>
     * }
     */
    public function describe(): array
    {
        throw new \LogicException('not implemented');
    }
}