<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Bridge\Symfony\Cache;

use DateTimeImmutable;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueCacheEntry;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueCacheInterface;
use Nowo\RuntimeSettingContract\Contract\StoredRuntimeValue;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function array_key_exists;
use function count;
use function hash;
use function is_array;
use function is_int;
use function is_string;
use function strlen;

/**
 * {@see RuntimeValueCacheInterface} over any PSR-6 pool (Symfony cache pools included; tag-aware pools work too).
 *
 * One item per setting, holding the version token it was stored under. {@see get()} returns the entry only for the
 * exact token the resolver read from the source (the namespace-version check), so a write anywhere makes older
 * entries unreachable; how often the token is re-read is the resolver's `version_ttl`. The item is validated
 * strictly on the way out: anything malformed, oversized or foreign is a miss (fail closed). Pool errors are misses
 * too, logged without values. Secrets never reach this class: the resolver keeps them out.
 */
final readonly class Psr6RuntimeValueCache implements RuntimeValueCacheInterface
{
    public const DEFAULT_LIFETIME = 3600;

    private LoggerInterface $logger;

    public function __construct(
        private CacheItemPoolInterface $pool,
        private int $lifetime = self::DEFAULT_LIFETIME,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function get(RuntimeSettingId $id, string $version): ?RuntimeValueCacheEntry
    {
        try {
            $item = $this->pool->getItem($this->itemKey($id));
            if (!$item->isHit()) {
                return null;
            }

            return $this->decode($item->get(), $version);
        } catch (Throwable $e) {
            $this->logger->warning('Runtime setting cache read failed for {id}; reading the source.', ['id' => $id->toString(), 'exception' => $e::class]);

            return null;
        }
    }

    public function set(RuntimeSettingId $id, string $version, ?StoredRuntimeValue $value): void
    {
        try {
            $item = $this->pool->getItem($this->itemKey($id));
            $item->set([
                'v' => $version,
                'c' => $value?->current,
                'p' => $value?->previous,
                'e' => $value?->previousExpiresAt?->getTimestamp(),
            ]);
            $item->expiresAfter($this->lifetime);
            $this->pool->save($item);
        } catch (Throwable $e) {
            $this->logger->warning('Runtime setting cache write failed for {id}.', ['id' => $id->toString(), 'exception' => $e::class]);
        }
    }

    private function itemKey(RuntimeSettingId $id): string
    {
        // PSR-6 keys allow [A-Za-z0-9_.]; hashing also keeps them short and free of reserved characters.
        return 'nowo_runtime_setting.' . hash('sha256', $id->toString());
    }

    private function decode(mixed $payload, string $version): ?RuntimeValueCacheEntry
    {
        if (!is_array($payload) || count($payload) !== 4
            || !array_key_exists('v', $payload) || !array_key_exists('c', $payload)
            || !array_key_exists('p', $payload) || !array_key_exists('e', $payload)
            || !is_string($payload['v']) || strlen($payload['v']) > RuntimeSettingResolver::MAX_VERSION_LENGTH
            || $payload['v'] !== $version
        ) {
            return null;
        }

        $current = $payload['c'];
        if ($current === null) {
            return $payload['p'] === null && $payload['e'] === null ? new RuntimeValueCacheEntry(null) : null;
        }
        if (!is_string($current) || strlen($current) > RuntimeSettingType::MAX_LENGTH) {
            return null;
        }

        $previous = $payload['p'];
        $expires  = $payload['e'];
        if ($previous === null && $expires === null) {
            return new RuntimeValueCacheEntry(new StoredRuntimeValue($current));
        }
        if (!is_string($previous) || strlen($previous) > RuntimeSettingType::MAX_LENGTH || !is_int($expires)) {
            return null;
        }

        return new RuntimeValueCacheEntry(new StoredRuntimeValue($current, $previous, new DateTimeImmutable('@' . $expires)));
    }
}
