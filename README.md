# filelock —— PHP 8 的建议性文件锁语义层

在一条可注入的锁后端之上，实现共享 / 独占锁的**语义层**：冲突处理、可重入、公平排队、
原地升降级与确定性快照。库本身**不碰真实文件系统**，所有后端操作都通过注入的
`LockBackend` 完成。

- 语言/框架：PHP 8，**仅标准库**（php-cli，无 composer，无第三方依赖，不用 mbstring）。
- 自检入口：`bash scripts/check.sh`（内部就是 `php check/check.php`）。
  `check/` 是**固定验收程序**，不要改。
- 类都在 `src/` 下：`FileLock`（语义层）、`LockBackend`（可注入后端接口）、`LockException`。

## 快速开始

```bash
php -l src/FileLock.php          # 语法检查
php tests/run.php                # 既有用例，12 个
bash scripts/check.sh            # 跑全部 8 个场景，全过才 exit 0
bash scripts/check.sh -list      # 列出全部场景
bash scripts/check.sh --only fairness
```

## 公开 API

```php
namespace Filelock;

final class FileLock
{
    public const SHARED = 1;
    public const EXCLUSIVE = 2;

    public function __construct(LockBackend $backend, string $owner);

    public function acquire(string $name, int $mode, bool $blocking = true): bool;
    public function release(string $name): void;
    public function convert(string $name, int $mode): bool;
    public function describe(): array;
}

interface LockBackend { /* 见 src/LockBackend.php */ }

final class LockException extends \RuntimeException {}
```

公开方法名与签名已定死，不得更改；可以新增类文件。

## 对外契约（9 条）

1. **后端注入**：所有锁的授予 / 转换 / 释放 / 排队都经注入的 `LockBackend` 完成。
   实现**不得**触碰真实文件系统、不得 `sleep` / `usleep`、不得读墙钟（时间只问 `backend->now()`）。
2. **模式与可重入**：`$mode` 只允许 `SHARED` / `EXCLUSIVE`，其它值抛 `LockException`。
   同一 `$owner` 对同一 `$name` 以**相同模式**重复 `acquire` 是**可重入**的：每次 `acquire` 计数加一，
   需要同样次数的 `release` 才真正释放；重复 `acquire` 期间不得重复调用后端的 `lock()`。
3. **冲突处理**：本项目在别处已持有 `$name` 时，
   - `$blocking === false`：立即返回 `false`，**且不得入队**；
   - `$blocking === true`：把本实例登记进等待队列（用 `backend->enqueue` 取票号）并返回 `false`；
     **绝不得阻塞或 sleep**。被唤醒后再次调用 `acquire` 即命中已授予的锁。
4. **公平唤醒（FIFO）**：`release` 使锁真正空闲后，必须**按票号升序**服务等待队列：
   先服务队首；若队首申请的是 `SHARED`，则**接着**服务其后连续的 `SHARED` 等待者（成批授予），
   遇到第一个 `EXCLUSIVE` 就停下；若队首申请的是 `EXCLUSIVE`，只服务队首一个。
   被服务的等待者必须从队列中出队。**不许惊群**：不得把所有等待者一次性放行。
5. **原地转换（单调）**：持有 `EXCLUSIVE` 时转 `SHARED` 允许且**原子**（走 `backend->convert`，
   不得先 `unlock` 再 `lock`，也不得改变持有者集合）；持有时反向（`SHARED → EXCLUSIVE`）抛 `LockException`。
   未持有 `$name` 就 `convert` 抛 `LockException`；`$mode` 非法抛 `LockException`。
6. **释放语义**：未持有 `$name` 就 `release` 抛 `LockException`；`release` 只把计数减一，
   计数归零时才调用后端的 `unlock`，然后执行第 4 条的公平唤醒。
7. **活性（无饿死、不丢唤醒）**：只要 `$name` 上还有等待者、并且此刻存在可被授予的持有模式，
   `release` 之后就必须**恰好**有等待者被授予（不得漏唤、不得让等待者永久滞留）。
   等待图只允许单一资源名的依赖链，实现不得在持锁期间再获取第二个资源名而阻塞。
8. **快照**：`describe()` 返回 `['held' => [...], 'waiting' => [...]]`：
   - `held` 每项 `['name' => string, 'mode' => int, 'owners' => string[]]`，只含当前有持有者的资源名，
     按 `name` 的**字节序**升序；`owners` 也按字节序升序；
   - `waiting` 每项 `['name' => string, 'owner' => string, 'mode' => int, 'ticket' => int]`，
     覆盖全部等待者，按 `(name 字节序, ticket 升序)` 排列；
   - 两个列表都为空时是 `[]`（数组，不是对象）。
9. **确定性**：同一操作序列跑出的结果逐项相同；所有排序必须显式指定（字节序 / 票号），
   不得依赖 PHP 数组的内部哈希顺序；任何判断都不得读墙钟。

## 目录

```
README.md            本文件
PROMPT.md            出题用的 User Prompt
autoload.php         极简 spl_autoload_register（__DIR__ 相对定位 src/）
src/FileLock.php     锁语义层            ← 现在只有签名，方法体全抛错
src/LockBackend.php  可注入后端接口
src/LockException.php 统一异常
tests/run.php        既有用例，12 个，起点全红
check/check.php      固定验收程序（别改）
scripts/check.sh     固定验收的 shell 入口
```