<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

use DateTimeImmutable;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingNotWritable;

/**
 * Port that changes stored values. Use it through {@see RuntimeSettingUpdater}, which applies the rules (registered
 * settings only, not `source: env`, type, bounds and validator first) and publishes the change after commit; the
 * console commands and an admin UI share it.
 *
 * Contract for implementations:
 * - **One unit of work per call.** Write the value, bump the namespace version token (see
 *   {@see RuntimeValueSourceInterface}) and write the audit entry in one transaction that the writer opens and
 *   commits before returning. Refuse with {@see RuntimeSettingNotWritable::insideTransaction()} when the caller
 *   already has a transaction open: a savepoint can still be rolled back after the writer returns, and the change
 *   would then be published although it never committed (audit lessons QL-M1 / QL-M2).
 * - **Values arrive validated** and formatted as strings. Encrypt secrets ({@see RuntimeSettingDefinition::$secret})
 *   at rest.
 * - **Audit.** Record actor, id, action and the old and new fingerprints from the context's fingerprinter, never the
 *   value. Record it once the write committed (or in the same transaction).
 * - **Rotation.** {@see rotate()} moves the current value to `previous` with the given expiry and stores the new one
 *   as current. {@see set()} and {@see unset()} drop any previous value. Purge an expired previous value on the next
 *   write at the latest.
 * - **No events.** Do not dispatch {@see SettingChangedEvent}: the updater does, after the call returned.
 * - **Worker mode.** No per-request state.
 */
interface RuntimeSettingWriterInterface
{
    /**
     * @throws RuntimeSettingNotWritable
     */
    public function set(RuntimeSettingDefinition $definition, string $value, RuntimeSettingWriteContext $context): RuntimeSettingChange;

    /**
     * @throws RuntimeSettingNotWritable
     */
    public function unset(RuntimeSettingDefinition $definition, RuntimeSettingWriteContext $context): RuntimeSettingChange;

    /**
     * @throws RuntimeSettingNotWritable
     */
    public function rotate(RuntimeSettingDefinition $definition, string $value, DateTimeImmutable $previousExpiresAt, RuntimeSettingWriteContext $context): RuntimeSettingChange;
}
