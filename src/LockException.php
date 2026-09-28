<?php

declare(strict_types=1);

namespace Filelock;

/**
 * 锁语义层的统一异常：非法模式、未持有就释放、不允许的转换等。
 */
final class LockException extends \RuntimeException
{
}