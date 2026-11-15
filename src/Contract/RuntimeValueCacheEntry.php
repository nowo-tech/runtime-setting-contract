<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

/**
 * A cache hit. `value` null means "the setting is unset in the source" (a cached absence).
 */
final readonly class RuntimeValueCacheEntry
{
    public function __construct(
        public ?StoredRuntimeValue $value,
    ) {
    }
}
