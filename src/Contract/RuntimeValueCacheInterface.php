<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

/**
 * Optional shared cache (for example a PSR-6 pool) for non-secret stored values, keyed by id and version token.
 *
 * An entry is only returned for the exact token it was stored under, so a write elsewhere (which changes the
 * token) makes every older entry unreachable without any invalidation message. Implementations must treat a
 * malformed or foreign entry as a miss, and must not throw: a cache failure is a miss.
 */
interface RuntimeValueCacheInterface
{
    public function get(RuntimeSettingId $id, string $version): ?RuntimeValueCacheEntry;

    public function set(RuntimeSettingId $id, string $version, ?StoredRuntimeValue $value): void;
}
