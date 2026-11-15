<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\InMemory;

use DateTimeImmutable;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingAction;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingChange;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriteContext;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriterInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueSourceInterface;
use Nowo\RuntimeSettingContract\Contract\StoredRuntimeValue;
use Nowo\RuntimeSettingContract\Contract\SystemClock;
use Psr\Clock\ClockInterface;

use function array_slice;
use function bin2hex;
use function count;
use function random_bytes;

/**
 * Reference source and writer kept in memory: for tests, for examples, and for hosts that set values at boot.
 *
 * It follows the whole contract (never-reused version tokens, rotation, purge of expired previous values, an audit
 * trail without values) but holds data for the lifetime of the object only. In a long-running worker that is the
 * process lifetime: it is a store, not a request cache, so it is deliberately not reset between requests.
 */
final class InMemoryRuntimeSettingStore implements RuntimeValueSourceInterface, RuntimeSettingWriterInterface
{
    public const INITIAL_VERSION = '0';

    /** The audit trail keeps the most recent entries only, so memory stays flat in a long-running process. */
    public const MAX_AUDIT_ENTRIES = 1000;

    /** @var array<string, array<string, StoredRuntimeValue>> by namespace, then key */
    private array $values = [];

    /** @var array<string, string> */
    private array $versions = [];

    /** @var list<array{actor: string, id: string, action: string, old: ?string, new: ?string, at: DateTimeImmutable}> */
    private array $audit = [];

    private int $counter = 0;

    private readonly ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function namespaceVersion(string $namespace): string
    {
        return $this->versions[$namespace] ?? self::INITIAL_VERSION;
    }

    public function read(RuntimeSettingId $id): ?StoredRuntimeValue
    {
        return $this->values[$id->namespace][$id->key] ?? null;
    }

    public function set(RuntimeSettingDefinition $definition, string $value, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        return $this->write($definition->id, RuntimeSettingAction::Set, new StoredRuntimeValue($value), $context);
    }

    public function unset(RuntimeSettingDefinition $definition, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        return $this->write($definition->id, RuntimeSettingAction::Unset, null, $context);
    }

    public function rotate(RuntimeSettingDefinition $definition, string $value, DateTimeImmutable $previousExpiresAt, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        $old = $this->read($definition->id);

        return $this->write(
            $definition->id,
            RuntimeSettingAction::Rotate,
            $old instanceof StoredRuntimeValue ? new StoredRuntimeValue($value, $old->current, $previousExpiresAt) : new StoredRuntimeValue($value),
            $context,
        );
    }

    /**
     * Audit trail: actor, id, action, fingerprints and time; never a value.
     *
     * @return list<array{actor: string, id: string, action: string, old: ?string, new: ?string, at: DateTimeImmutable}>
     */
    public function auditLog(): array
    {
        return $this->audit;
    }

    private function write(RuntimeSettingId $id, RuntimeSettingAction $action, ?StoredRuntimeValue $new, RuntimeSettingWriteContext $context): RuntimeSettingChange
    {
        $old            = $this->read($id);
        $oldFingerprint = $old instanceof StoredRuntimeValue ? $context->fingerprinter->fingerprint($id, $old->current) : null;
        $newFingerprint = $new instanceof StoredRuntimeValue ? $context->fingerprinter->fingerprint($id, $new->current) : null;

        if (!$new instanceof StoredRuntimeValue) {
            // @igor-ignore - Reference in-memory store: holding the data is its purpose (a store, not a request cache); bounded audit trail.
            unset($this->values[$id->namespace][$id->key]);
        } else {
            // @igor-ignore - Reference in-memory store: holding the data is its purpose (a store, not a request cache); bounded audit trail.
            $this->values[$id->namespace][$id->key] = $new;
        }
        $this->purgeExpired($id->namespace);

        // Counter plus random part: a token is never handed out twice (see RuntimeValueSourceInterface).
        // @igor-ignore - Reference in-memory store: holding the data is its purpose (a store, not a request cache); bounded audit trail.
        $version = ++$this->counter . '-' . bin2hex(random_bytes(8));
        // @igor-ignore - Reference in-memory store: holding the data is its purpose (a store, not a request cache); bounded audit trail.
        $this->versions[$id->namespace] = $version;

        // @igor-ignore - Reference in-memory store: holding the data is its purpose (a store, not a request cache); bounded audit trail.
        $this->audit[] = [
            'actor'  => $context->actor,
            'id'     => $id->toString(),
            'action' => $action->value,
            'old'    => $oldFingerprint,
            'new'    => $newFingerprint,
            'at'     => $this->clock->now(),
        ];
        if (count($this->audit) > self::MAX_AUDIT_ENTRIES) {
            // @igor-ignore - Reference in-memory store: holding the data is its purpose (a store, not a request cache); bounded audit trail.
            $this->audit = array_slice($this->audit, -self::MAX_AUDIT_ENTRIES);
        }

        return new RuntimeSettingChange($id, $action, $oldFingerprint, $newFingerprint, $version);
    }

    private function purgeExpired(string $namespace): void
    {
        $now = $this->clock->now();
        // @igor-ignore - Reference in-memory store: holding the data is its purpose (a store, not a request cache); bounded audit trail.
        foreach ($this->values[$namespace] ?? [] as $key => $stored) {
            if ($stored->previous !== null && $stored->previousAt($now) === null) {
                // @igor-ignore - Reference in-memory store: holding the data is its purpose (a store, not a request cache); bounded audit trail.
                $this->values[$namespace][$key] = new StoredRuntimeValue($stored->current);
            }
        }
    }
}
