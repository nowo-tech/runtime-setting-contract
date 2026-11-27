<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract\Exception;

use RuntimeException;

use function sprintf;

/**
 * The setting cannot be changed at runtime, or no writer is configured.
 */
final class RuntimeSettingNotWritable extends RuntimeException implements RuntimeSettingExceptionInterface
{
    public static function envSource(string $id): self
    {
        return new self(sprintf('Runtime setting "%s" uses "source: env": an environment value cannot change at runtime. Change the environment and restart, or configure "source: db" or "db_then_env".', $id));
    }

    /**
     * 1.1: the setting reads its value from a read-only provider.
     */
    public static function readOnlyProvider(string $id, string $provider): self
    {
        return new self(sprintf('Runtime setting "%s" is read from the read-only provider "%s": change the value where that provider reads it.', $id, $provider));
    }

    public static function noWriter(): self
    {
        return new self('No runtime setting writer is configured.');
    }

    public static function insideTransaction(): self
    {
        return new self('A runtime setting write must run in its own transaction, not inside a transaction the caller opened.');
    }
}
