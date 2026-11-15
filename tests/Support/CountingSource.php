<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Support;

use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueSourceInterface;
use Nowo\RuntimeSettingContract\Contract\StoredRuntimeValue;
use Throwable;

/**
 * Decorates a source: counts calls and can fail on demand.
 */
final class CountingSource implements RuntimeValueSourceInterface
{
    public int $versionReads = 0;

    public int $valueReads = 0;

    public ?Throwable $failWith = null;

    public ?string $forcedVersion = null;

    public function __construct(private readonly RuntimeValueSourceInterface $inner)
    {
    }

    public function namespaceVersion(string $namespace): string
    {
        ++$this->versionReads;
        if ($this->failWith instanceof Throwable) {
            throw $this->failWith;
        }

        return $this->forcedVersion ?? $this->inner->namespaceVersion($namespace);
    }

    public function read(RuntimeSettingId $id): ?StoredRuntimeValue
    {
        ++$this->valueReads;
        if ($this->failWith instanceof Throwable) {
            throw $this->failWith;
        }

        return $this->inner->read($id);
    }
}
