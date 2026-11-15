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
 * - **Audit.** Record actor, id, action and the old and new fingerprints from
 *   {@see RuntimeSettingWriteContext::fingerprint()}, never the value. For a secret that method returns null: record
 *   only that the secret changed, never a fingerprint (audit QS-M-1). A writer that skips a write whose value equals
 *   the stored one must still bump the version token and write the audit entry: otherwise the absence of a bump or an
 *   entry tells a caller that its guess equals the stored value (an equality oracle, Delta QS-D-I). Return the same
 *   (null) fingerprints in the
 *   {@see RuntimeSettingChange}. Record the entry once the write committed (or in the same transaction).
 * - **Rotation.** {@see rotate()} moves the current value to `previous` with the given expiry and stores the new one
 *   as current. {@see set()} and {@see unset()} drop any previous value.
 * - **Purge.** Every write also deletes the expired previous values of its namespace (at least), and
 *   {@see purgeExpired()} deletes every expired previous value of the store. The updater calls it from
 *   `RuntimeSettingUpdater::purgeExpired()`, which the `nowo:runtime-setting:purge` command (or a host's scheduled
 *   task or message) runs. A previous value is never served after its expiry anyway; the purge removes it at rest.
 *   Purge with a conditional UPDATE / DELETE on the expiry (`... WHERE previous_expires_at <= :now`), never by
 *   reading rows and writing them back: a rotation committed meanwhile must not lose its new previous value.
 * - **Ids.** Store namespace and key verbatim and compare them exactly; never derive one id form from the other
 *   (`chat.retention_days` and `CHAT_RETENTION_DAYS` are two settings).
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

    /**
     * Deletes every previous value whose expiry is at or before `$now`, in its own transaction, and bumps the version
     * token of each namespace it changed. Returns how many previous values were deleted.
     *
     * @throws RuntimeSettingNotWritable
     */
    public function purgeExpired(DateTimeImmutable $now): int;
}
