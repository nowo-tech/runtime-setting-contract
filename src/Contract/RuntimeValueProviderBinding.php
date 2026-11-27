<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;

use function is_string;
use function preg_match;
use function strlen;

/**
 * 1.1: one named {@see RuntimeValueProviderInterface} and how long the shared cache may keep its non-secret values.
 */
final readonly class RuntimeValueProviderBinding
{
    /** Provider names: lowercase snake case, at most 64 bytes. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';

    /** Longest shared-cache TTL of a provider, in seconds. */
    public const MAX_CACHE_TTL = 3600;

    /**
     * @param int $cacheTtl seconds a non-secret value may be served from the shared cache (0: never cached; read once
     *                      per request). Secrets are never cached, whatever this says.
     *
     * @throws RuntimeSettingConfigurationError when the name or the TTL is invalid
     */
    public function __construct(
        public string $name,
        public RuntimeValueProviderInterface $provider,
        public int $cacheTtl = 0,
    ) {
        self::assertValid($name, $cacheTtl);
    }

    /**
     * @throws RuntimeSettingConfigurationError when the name or the TTL is invalid
     */
    public static function assertValid(string $name, int $cacheTtl): void
    {
        if (!self::isValidName($name)) {
            throw RuntimeSettingConfigurationError::because('Invalid runtime value provider name: use lowercase letters, digits and underscores, starting with a letter (at most 64 bytes).');
        }
        if ($cacheTtl < 0 || $cacheTtl > self::MAX_CACHE_TTL) {
            throw RuntimeSettingConfigurationError::because('The cache TTL of runtime value provider "' . $name . '" must be between 0 and ' . self::MAX_CACHE_TTL . ' seconds.');
        }
    }

    public static function isValidName(mixed $name): bool
    {
        return is_string($name) && strlen($name) <= 64 && preg_match(self::NAME_PATTERN, $name) === 1;
    }
}
