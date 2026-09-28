锁的语义要自己实现一层。filelock 是 PHP 8 的建议性文件锁库（php-cli，无 composer，
无第三方依赖），自检走 `scripts/check.sh`（`check/` 是固定验收程序，别改），
既有用例走 `php tests/run.php`。

`src/LockBackend.php` 是可注入的锁后端接口（判据会注入一个假后端，不碰真实文件系统），
`src/LockException.php` 已经给全；`src/FileLock.php` 现在只有签名，方法体全抛错。

任务：按 README「对外契约」的 9 条把 `FileLock` 实现出来，让 12 个既有用例转绿、固定件全过。

验收：
- php -l src/FileLock.php 退出码 0；
- php tests/run.php 12/12 全绿；
- php check/check.php 退出码 0，8 个场景全过（basic 2 + blocking 2 + fairness 2 + upgrade 1 + snapshot 1）。

约束：
1. 不改 `check/`、不改 `src/LockBackend.php` 与 `src/LockException.php`；可以新增类文件。
2. 对外方法名与签名已定死，不要改；`tests/run.php` 里的用例一条都不许删或改。
3. 不许用 composer，不许引入任何第三方库；不许用 mbstring 扩展。
4. 判据全部走注入后端：不许用真实文件锁、不许 sleep、不许读墙钟。