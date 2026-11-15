<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract\Exception;

use LogicException;

use function sprintf;

/**
 * A definition or a registry is wired wrong. This is a programming or configuration error, raised early.
 */
final class RuntimeSettingConfigurationError extends LogicException implements RuntimeSettingExceptionInterface
{
    public static function duplicate(string $id): self
    {
        return new self(sprintf('Runtime setting "%s" is registered twice.', $id));
    }

    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
