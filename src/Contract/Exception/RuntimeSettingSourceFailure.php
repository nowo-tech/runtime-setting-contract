<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract\Exception;

use RuntimeException;
use Throwable;

use function sprintf;

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

    /**
     * 1.1: a provider failed. Only the provider name and the exception class are kept: the provider's own message and
     * chain may quote SQL or a value, so they are dropped.
     */
    public static function providerFailed(string $provider, string $exceptionClass): self
    {
        return new self(sprintf('Runtime value provider "%s" could not be read (%s).', $provider, $exceptionClass));
    }

    public static function notConfigured(): self
    {
        return new self('No runtime setting source is configured.');
    }
}
