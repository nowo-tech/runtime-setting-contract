<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Bridge\Symfony;

use DateTimeImmutable;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingNotWritable;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingSourceFailure;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingChange;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriteContext;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriterInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueSourceInterface;
use Nowo\RuntimeSettingContract\Contract\StoredRuntimeValue;

/**
 * Bound when the host configured no source or writer: every read is a source failure (so `on_error` decides), every
 * write is refused. Settings with `source: env` never reach it.
 */
final readonly class UnconfiguredRuntimeSettingStore implements RuntimeValueSourceInterface, RuntimeSettingWriterInterface
{
    public function namespaceVersion(string $namespace): string
    {
        throw RuntimeSettingSourceFailure::notConfigured();
    }

    public function read(RuntimeSettingId $id): ?StoredRuntimeValue
    {
        throw RuntimeSettingSourceFailure::notConfigured();
    }

    public function set(RuntimeSettingDefinition $definition, string $value, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        throw RuntimeSettingNotWritable::noWriter();
    }

    public function unset(RuntimeSettingDefinition $definition, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        throw RuntimeSettingNotWritable::noWriter();
    }

    public function rotate(RuntimeSettingDefinition $definition, string $value, DateTimeImmutable $previousExpiresAt, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        throw RuntimeSettingNotWritable::noWriter();
    }

    public function purgeExpired(DateTimeImmutable $now): int
    {
        throw RuntimeSettingNotWritable::noWriter();
    }
}
