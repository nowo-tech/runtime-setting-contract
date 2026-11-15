<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract\Exception;

use RuntimeException;
use Throwable;

/**
 * A source reports that it cannot be read (database down, malformed row, no source configured).
 *
 * Sources may throw any exception; the resolver treats every one as a source failure and applies `on_error`.
 */
final class RuntimeSettingSourceFailure extends RuntimeException implements RuntimeSettingExceptionInterface
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self($reason, 0, $previous);
    }

    public static function notConfigured(): self
    {
        return new self('No runtime setting source is configured.');
    }
}
