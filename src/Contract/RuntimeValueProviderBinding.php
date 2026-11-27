<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;

use function hash;
use function is_string;
use function preg_match;
use function strlen;
use function substr;

/**
 * 1.1: one named {@see RuntimeValueProviderInterface} and how long the shared cache may keep its non-secret values.
 */
final readonly class RuntimeValueProviderBinding
{
    /** Provider names: lowercase snake case, at most 64 bytes. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';

    /** Longest shared-cache TTL of a provider, in seconds. */
    public const MAX_CACHE_TTL = 3600;

    /** Longest cache scope accepted (it is hashed into the cache token, never stored as is). */
    public const MAX_CACHE_SCOPE_LENGTH = 4096;

    /**
     * @param int $cacheTtl seconds a non-secret value may be served from the shared cache (0: never cached; read once
     *                      per request). Secrets are never cached, whatever this says.
     * @param string $cacheScope 1.1: what the provider's answer depends on besides the setting id (connection, query,
     *                           bound constants, deployment), hashed into the cache token so two providers or two
     *                           deployments with different scopes never share an entry; '' = no scope
     *
     * @throws RuntimeSettingConfigurationError when the name, the TTL or the scope is invalid
     */
    public function __construct(
        public string $name,
        public RuntimeValueProviderInterface $provider,
        public int $cacheTtl = 0,
        public string $cacheScope = '',
    ) {
        self::assertValid($name, $cacheTtl);
        if (strlen($cacheScope) > self::MAX_CACHE_SCOPE_LENGTH) {
            throw RuntimeSettingConfigurationError::because('The cache scope of runtime value provider "' . $name . '" is longer than ' . self::MAX_CACHE_SCOPE_LENGTH . ' bytes.');
        }
    }

    /**
     * The part of the shared-cache token that identifies this binding: name, TTL and, when set, a hash of the scope.
     * At most 64 + 1 + 4 + 1 + 16 bytes.
     */
    public function cacheTokenPrefix(): string
    {
        return $this->name . ':' . $this->cacheTtl . ($this->cacheScope === '' ? '' : ':' . substr(hash('sha256', $this->cacheScope), 0, 16));
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
