<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingSourceFailure;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingUnavailable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function count;
use function intdiv;
use function strlen;

/**
 * Resolves the value of a setting from its source, its configured (env) value and its default, worker-safe.
 *
 * - **Per-request memo.** One value per setting per request. The host clears it at every request boundary with
 *   {@see clearRuntimeCache()} (the Symfony bridge does it on main `kernel.request`, `kernel.terminate`,
 *   `console.terminate` and the Messenger worker events) and {@see reset()} (`kernel.reset`). It never carries a
 *   value from one request to the next.
 * - **Namespace version.** The source's version token is read at most once per request per namespace, and reused
 *   across requests for at most `versionTtl` seconds (0: read it on every request). A write anywhere therefore
 *   reaches this process within `versionTtl`, without a restart and without a shared cache.
 * - **Optional shared cache.** Non-secret stored values are cached under their version token; an entry for another
 *   token is never used. Secrets never leave this process's per-request memo.
 * - **Validation.** Every value, whatever its origin (stored, cached, configured, default), goes through the type
 *   and the bundle's validator before it is returned.
 * - **Providers (1.1).** A setting with `provider: <name>` reads its value from that read-only
 *   {@see RuntimeValueProviderInterface} instead of the source, with no namespace version: its shared-cache entries
 *   live for the provider's cache TTL (a time bucket; 0: never cached, read once per request). Secrets stay in the
 *   per-request memo only. A provider's exception is replaced by a {@see RuntimeSettingSourceFailure} without its
 *   message or chain, and values longer than {@see RuntimeSettingType::MAX_LENGTH} are refused before parsing.
 * - **Failures.** When the source cannot be read, or holds a value the validator refuses, `on_error: fallback` logs a
 *   warning (the id only) and uses the configured value; `fail_closed` throws.
 *
 * Bounded state: the memo and the version map hold at most {@see self::MAX_ENTRIES} entries each.
 */
final class RuntimeSettingResolver
{
    public const DEFAULT_VERSION_TTL = 5;

    public const MAX_VERSION_TTL = 300;

    public const MAX_ENTRIES = 1024;

    public const MAX_VERSION_LENGTH = 128;

    /** @var array<string, array{value: bool|float|int|string|null, candidates: list<bool|float|int|string>}> per-request memo, by id */
    private array $memo = [];

    /** @var array<string, string> version tokens already read during this request, by namespace */
    private array $requestVersions = [];

    /** @var array<string, array{token: string, at: float}> version tokens reused across requests for versionTtl seconds */
    private array $versions = [];

    private readonly ClockInterface $clock;

    private readonly LoggerInterface $logger;

    /**
     * @throws RuntimeSettingConfigurationError when versionTtl is outside 0..300
     */
    public function __construct(
        private readonly RuntimeValueSourceInterface $source,
        private readonly ?RuntimeValueCacheInterface $cache = null,
        ?ClockInterface $clock = null,
        private readonly int $versionTtl = self::DEFAULT_VERSION_TTL,
        ?LoggerInterface $logger = null,
        private readonly ?RuntimeValueProviderRegistry $providers = null,
    ) {
        if ($versionTtl < 0 || $versionTtl > self::MAX_VERSION_TTL) {
            throw RuntimeSettingConfigurationError::because('versionTtl must be between 0 and 300 seconds.');
        }
        $this->clock  = $clock ?? new SystemClock();
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * The value to use now: stored, configured (env) or default, validated; null when none applies.
     *
     * @throws RuntimeSettingUnavailable source failure under fail_closed, or a required setting without value
     * @throws InvalidRuntimeSettingValue stored value refused under fail_closed, or configured value refused
     * @throws RuntimeSettingConfigurationError the setting names a provider that is not registered
     */
    public function get(RuntimeSettingDefinition $definition): string|int|float|bool|null
    {
        return $this->resolve($definition)['value'];
    }

    /**
     * The values a verifier must accept now: the current one first, then the previous one while its rotation window
     * is open. Use it to check tokens, webhook signatures or credentials during a rotation.
     *
     * @throws RuntimeSettingUnavailable
     * @throws InvalidRuntimeSettingValue
     * @throws RuntimeSettingConfigurationError
     *
     * @return list<bool|float|int|string>
     */
    public function candidates(RuntimeSettingDefinition $definition): array
    {
        return $this->resolve($definition)['candidates'];
    }

    /**
     * Request boundary: drops the per-request memo and the version tokens read during the request. Version tokens
     * younger than versionTtl are kept on purpose.
     */
    public function clearRuntimeCache(): void
    {
        // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
        $this->memo = [];
        // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
        $this->requestVersions = [];
    }

    /**
     * After a write (committed or not): drops everything this process remembers about the namespace, so the next
     * read in this process sees the source's state.
     */
    public function forgetNamespace(string $namespace): void
    {
        // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
        unset($this->requestVersions[$namespace], $this->versions[$namespace]);
        // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
        foreach ($this->memo as $id => $_) {
            if ($this->namespaceOf($id) === $namespace) {
                // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
                unset($this->memo[$id]);
            }
        }
    }

    /**
     * `kernel.reset` / services resetter: drops all state, including the version tokens.
     */
    public function reset(): void
    {
        // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
        $this->memo = [];
        // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
        $this->requestVersions = [];
        // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
        $this->versions = [];
    }

    /**
     * @return array{value: bool|float|int|string|null, candidates: list<bool|float|int|string>}
     */
    private function resolve(RuntimeSettingDefinition $definition): array
    {
        $id = $definition->idString();
        if (isset($this->memo[$id])) {
            return $this->memo[$id];
        }

        $resolved = $definition->source === RuntimeSettingSource::Env
            ? $this->configured($definition)
            : $this->fromSource($definition);

        // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
        if (count($this->memo) >= self::MAX_ENTRIES) {
            // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
            $this->memo = [];
        }

        // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
        return $this->memo[$id] = $resolved;
    }

    /**
     * @return array{value: bool|float|int|string|null, candidates: list<bool|float|int|string>}
     */
    private function fromSource(RuntimeSettingDefinition $definition): array
    {
        // A missing provider is a wiring error, never a "source down" that on_error: fallback would hide.
        $binding = $definition->provider === null ? null : $this->binding($definition->provider);

        try {
            $stored = $binding instanceof RuntimeValueProviderBinding ? $this->readProvided($definition, $binding) : $this->readStored($definition);
        } catch (Throwable $e) {
            if ($definition->onError === RuntimeSettingErrorPolicy::FailClosed) {
                throw RuntimeSettingUnavailable::sourceFailed($definition->idString(), $e);
            }
            $this->logger->warning('Runtime setting {id}: the source could not be read; using the configured value.', [
                'id'        => $definition->idString(),
                'exception' => $e::class,
            ]);

            return $this->configured($definition);
        }

        if (!$stored instanceof StoredRuntimeValue) {
            return $definition->source === RuntimeSettingSource::Db
                ? $this->defaultOnly($definition)
                : $this->configured($definition);
        }

        try {
            $current = $definition->parseAndValidate($stored->current);
        } catch (InvalidRuntimeSettingValue $e) {
            if ($definition->onError === RuntimeSettingErrorPolicy::FailClosed) {
                throw InvalidRuntimeSettingValue::forSetting($definition->idString(), $e);
            }
            $this->logger->warning('Runtime setting {id}: the stored value is invalid; using the configured value.', [
                'id'     => $definition->idString(),
                'reason' => $e->getMessage(),
            ]);

            return $this->configured($definition);
        }

        $candidates = [$current];
        $previous   = $stored->previousAt($this->clock->now());
        if ($previous !== null) {
            try {
                $candidates[] = $definition->parseAndValidate($previous);
            } catch (InvalidRuntimeSettingValue $e) {
                $this->logger->warning('Runtime setting {id}: the previous value is invalid and is not accepted.', [
                    'id'     => $definition->idString(),
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return ['value' => $current, 'candidates' => $candidates];
    }

    /**
     * @throws Throwable any source or cache failure
     */
    private function readStored(RuntimeSettingDefinition $definition): ?StoredRuntimeValue
    {
        $version = $this->version($definition->namespace());
        // Secrets never go to the shared cache.
        $cache = $definition->secret ? null : $this->cache;

        $entry = $cache?->get($definition->id, $version);
        if ($entry instanceof RuntimeValueCacheEntry) {
            return $entry->value;
        }

        $stored = $this->source->read($definition->id);
        $cache?->set($definition->id, $version, $stored);

        return $stored;
    }

    private function binding(string $name): RuntimeValueProviderBinding
    {
        if (!$this->providers instanceof RuntimeValueProviderRegistry) {
            throw RuntimeSettingConfigurationError::unknownProvider($name);
        }

        return $this->providers->get($name);
    }

    /**
     * @throws Throwable any provider (sanitised) or cache failure
     */
    private function readProvided(RuntimeSettingDefinition $definition, RuntimeValueProviderBinding $binding): ?StoredRuntimeValue
    {
        // Secrets never go to the shared cache; a provider without TTL is read once per request.
        $cache   = $definition->secret || $binding->cacheTtl === 0 ? null : $this->cache;
        $version = $cache instanceof RuntimeValueCacheInterface ? $this->providerVersion($binding) : '';

        $entry = $cache?->get($definition->id, $version);
        if ($entry instanceof RuntimeValueCacheEntry) {
            return $entry->value;
        }

        try {
            $stored = $binding->provider->read($definition->id);
        } catch (Throwable $e) {
            // Its message or chain may quote SQL or a value: keep the class only.
            throw RuntimeSettingSourceFailure::providerFailed($binding->name, $e::class);
        }

        // Bounded before anything parses or caches it.
        if ($stored instanceof StoredRuntimeValue
            && (strlen($stored->current) > RuntimeSettingType::MAX_LENGTH || strlen($stored->previous ?? '') > RuntimeSettingType::MAX_LENGTH)
        ) {
            throw RuntimeSettingSourceFailure::because('Runtime value provider "' . $binding->name . '" returned a value longer than ' . RuntimeSettingType::MAX_LENGTH . ' bytes.');
        }

        $cache?->set($definition->id, $version, $stored);

        return $stored;
    }

    /**
     * A provider has no version token: its cache entries are valid for one time bucket of `cacheTtl` seconds, the
     * same bucket in every process. Changing the provider's name, TTL or cache scope changes the token.
     */
    private function providerVersion(RuntimeValueProviderBinding $binding): string
    {
        return 'provider:' . $binding->cacheTokenPrefix() . ':' . intdiv($this->clock->now()->getTimestamp(), $binding->cacheTtl);
    }

    private function version(string $namespace): string
    {
        if (isset($this->requestVersions[$namespace])) {
            return $this->requestVersions[$namespace];
        }

        $now = (float) $this->clock->now()->format('U.u');
        if ($this->versionTtl > 0 && isset($this->versions[$namespace])) {
            $age = $now - $this->versions[$namespace]['at'];
            if ($age >= 0 && $age < $this->versionTtl) {
                // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
                return $this->requestVersions[$namespace] = $this->versions[$namespace]['token'];
            }
        }

        $token = $this->source->namespaceVersion($namespace);
        if ($token === '' || strlen($token) > self::MAX_VERSION_LENGTH) {
            throw RuntimeSettingSourceFailure::because('The source returned an invalid version token.');
        }

        // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
        if (count($this->versions) >= self::MAX_ENTRIES) {
            // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
            $this->versions = [];
        }
        // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
        if (count($this->requestVersions) >= self::MAX_ENTRIES) {
            // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
            $this->requestVersions = [];
        }
        // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
        $this->versions[$namespace] = ['token' => $token, 'at' => $now];

        // @igor-ignore - Bounded per-request memo / version-token cache: cleared at every boundary by RuntimeSettingBoundaryListener (or the host) and by reset() (kernel.reset).
        return $this->requestVersions[$namespace] = $token;
    }

    /**
     * The configured (env) value, else the default; validated.
     *
     * @return array{value: bool|float|int|string|null, candidates: list<bool|float|int|string>}
     */
    private function configured(RuntimeSettingDefinition $definition): array
    {
        return $this->finalValue($definition, $definition->configuredValue() ?? $definition->defaultValue());
    }

    /**
     * @return array{value: bool|float|int|string|null, candidates: list<bool|float|int|string>}
     */
    private function defaultOnly(RuntimeSettingDefinition $definition): array
    {
        return $this->finalValue($definition, $definition->defaultValue());
    }

    /**
     * @return array{value: bool|float|int|string|null, candidates: list<bool|float|int|string>}
     */
    private function finalValue(RuntimeSettingDefinition $definition, string|int|float|bool|null $value): array
    {
        if ($value === null) {
            if ($definition->required) {
                throw RuntimeSettingUnavailable::missing($definition->idString());
            }

            return ['value' => null, 'candidates' => []];
        }

        try {
            $value = $definition->validate($value);
        } catch (InvalidRuntimeSettingValue $e) {
            throw InvalidRuntimeSettingValue::forSetting($definition->idString(), $e);
        }

        return ['value' => $value, 'candidates' => [$value]];
    }

    private function namespaceOf(string $id): string
    {
        return RuntimeSettingId::fromString($id)->namespace;
    }
}
