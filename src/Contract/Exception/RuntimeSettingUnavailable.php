<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract\Exception;

use RuntimeException;
use Throwable;

use function sprintf;

/**
 * The setting has no usable value: the source failed under `on_error: fail_closed`, or a required setting is unset.
 *
 * The source's own error stays in {@see getPrevious()}, for the logs only (it may contain SQL or credentials).
 */
final class RuntimeSettingUnavailable extends RuntimeException implements RuntimeSettingExceptionInterface
{
    public static function sourceFailed(string $id, Throwable $previous): self
    {
        return new self(sprintf('Runtime setting "%s" is unavailable: its source could not be read.', $id), 0, $previous);
    }

    public static function missing(string $id): self
    {
        return new self(sprintf('Runtime setting "%s" is required but has no value.', $id));
    }
}
