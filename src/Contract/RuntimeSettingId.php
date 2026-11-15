<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingId;
use Stringable;

use function explode;
use function preg_match;
use function str_contains;
use function strlen;

/**
 * Identifier of a runtime setting, in one of two forms.
 *
 * - `namespace.key` (`chat.retention_days`): the namespace is `[a-z][a-z0-9_]{0,63}`, the key is one or more
 *   `[a-z0-9_]+` segments joined by dots, at most 128 bytes. The namespace groups the settings whose version the
 *   source bumps together.
 * - An environment variable name (`CHAT_RETENTION_DAYS`): `[A-Z][A-Z0-9_]{0,127}`, the same rule as
 *   nowo-tech/runtime-env-bundle. These ids live in the reserved namespace {@see self::ENV_NAMESPACE}, so one version
 *   covers every env-named setting of a source (for example one `runtime_env_variables` table).
 *
 * Matching is byte for byte: ASCII only, no case folding, no trimming, no trailing newline (`/D`), length checked
 * before any pattern runs (REQ-SEC-006).
 */
final readonly class RuntimeSettingId implements Stringable
{
    public const ENV_NAMESPACE = 'env';

    public const ENV_NAME_PATTERN = '/^[A-Z][A-Z0-9_]{0,127}$/D';

    public const NAMESPACE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';

    public const KEY_PATTERN = '/^[a-z0-9_]+(?:\.[a-z0-9_]+)*$/D';

    public const MAX_KEY_LENGTH = 128;

    public const MAX_LENGTH = 193;

    private function __construct(
        public string $namespace,
        public string $key,
    ) {
    }

    /**
     * @throws InvalidRuntimeSettingId
     */
    public static function fromString(string $id): self
    {
        if ($id === '' || strlen($id) > self::MAX_LENGTH) {
            throw InvalidRuntimeSettingId::malformed();
        }

        if (!str_contains($id, '.')) {
            if (preg_match(self::ENV_NAME_PATTERN, $id) !== 1) {
                throw InvalidRuntimeSettingId::malformed();
            }

            return new self(self::ENV_NAMESPACE, $id);
        }

        [$namespace, $key] = explode('.', $id, 2);

        return self::of($namespace, $key);
    }

    /**
     * @throws InvalidRuntimeSettingId
     */
    public static function of(string $namespace, string $key): self
    {
        if ($namespace === self::ENV_NAMESPACE) {
            if (preg_match(self::ENV_NAME_PATTERN, $key) !== 1) {
                throw InvalidRuntimeSettingId::malformed();
            }

            return new self($namespace, $key);
        }

        if (strlen($namespace) > 64 || preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1) {
            throw InvalidRuntimeSettingId::malformed();
        }

        if (strlen($key) > self::MAX_KEY_LENGTH || preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw InvalidRuntimeSettingId::malformed();
        }

        return new self($namespace, $key);
    }

    public function isEnvName(): bool
    {
        return $this->namespace === self::ENV_NAMESPACE;
    }

    public function equals(self $other): bool
    {
        return $this->namespace === $other->namespace && $this->key === $other->key;
    }

    public function toString(): string
    {
        return $this->isEnvName() ? $this->key : $this->namespace . '.' . $this->key;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
