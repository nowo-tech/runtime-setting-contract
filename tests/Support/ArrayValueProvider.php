<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Support;

use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueProviderInterface;
use Nowo\RuntimeSettingContract\Contract\StoredRuntimeValue;
use Throwable;

use function is_string;

/**
 * A read-only provider over an array, as a host's per-tenant lookup would be: counts reads, fails on demand.
 */
final class ArrayValueProvider implements RuntimeValueProviderInterface
{
    public int $reads = 0;

    public ?Throwable $failWith = null;

    /**
     * @param array<string, StoredRuntimeValue|string> $values by id
     */
    public function __construct(public array $values = [])
    {
    }

    public function read(RuntimeSettingId $id): ?StoredRuntimeValue
    {
        ++$this->reads;
        if ($this->failWith instanceof Throwable) {
            throw $this->failWith;
        }

        $value = $this->values[$id->toString()] ?? null;

        return is_string($value) ? new StoredRuntimeValue($value) : $value;
    }
}
