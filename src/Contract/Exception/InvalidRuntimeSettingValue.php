<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract\Exception;

use InvalidArgumentException;
use Throwable;

use function sprintf;

/**
 * A value was refused: wrong type, too long, or rejected by the bundle's validator.
 *
 * The message carries a reason, never the value. A validator throws this (with a reason that does not quote the
 * value) to refuse a value; any other exception a validator throws is wrapped into this one.
 */
final class InvalidRuntimeSettingValue extends InvalidArgumentException implements RuntimeSettingExceptionInterface
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self($reason, 0, $previous);
    }

    public static function forSetting(string $id, self $reason): self
    {
        return new self(sprintf('Invalid value for runtime setting "%s": %s', $id, $reason->getMessage()), 0, $reason);
    }
}
