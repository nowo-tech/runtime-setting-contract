<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

/**
 * 1.1: a **read-only** provider of runtime setting values, chosen per setting (`provider: <name>`), for values that
 * live somewhere else than the default source: a per-tenant table, a join over host tables, a parameterised query
 * (nowo-tech/runtime-env-bundle ships a DBAL query provider configured in YAML).
 *
 * A provider only reads. Settings bound to one cannot be set, unset or rotated through
 * {@see RuntimeSettingUpdater} (they throw {@see Exception\RuntimeSettingNotWritable}); change their value where the
 * provider reads it.
 *
 * Contract for implementations:
 * - **Read only.** Never write, lock or change session state. Prefer a database user that can only SELECT.
 * - **Committed data**, read on every call (no ORM identity map kept across requests).
 * - **Bounded.** At most one row; strings at most {@see RuntimeSettingType::MAX_LENGTH} bytes (the resolver refuses
 *   anything longer before parsing, and the setting's own `maxLength` applies too).
 * - **Plain strings.** {@see read()} returns the value as a string (and, during a rotation window, the previous one
 *   with its expiry); null when the provider has no value for the setting.
 * - **Failures.** Any exception means "cannot read now"; the resolver replaces it with a
 *   {@see Exception\RuntimeSettingSourceFailure} that carries neither its message nor its chain (it may quote SQL or a
 *   value) and applies the setting's `on_error`.
 * - **Worker mode.** No per-request state: one instance serves every request of a long-running worker.
 *
 * Caching is not the provider's job: the resolver keeps values in its per-request memo and, when the provider is
 * registered with a cache TTL, in the shared cache (never secrets).
 */
interface RuntimeValueProviderInterface
{
    public function read(RuntimeSettingId $id): ?StoredRuntimeValue;
}
