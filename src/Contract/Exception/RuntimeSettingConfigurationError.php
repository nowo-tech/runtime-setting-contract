<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract\Exception;

use LogicException;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueProviderBinding;

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

    /**
     * 1.1: a setting names a provider nobody registered (the name is echoed only when it is well formed).
     */
    public static function unknownProvider(string $name): self
    {
        return new self(RuntimeValueProviderBinding::isValidName($name)
            ? sprintf('No runtime value provider is registered under "%s".', $name)
            : 'No runtime value provider is registered under that name.');
    }
}
