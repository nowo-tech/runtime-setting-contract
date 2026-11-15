<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

/**
 * Port to the store that holds runtime setting values (a database table, a host repository).
 *
 * Contract for implementations:
 * - **Version token.** {@see namespaceVersion()} returns an opaque, non-empty token (at most 128 bytes) that changes
 *   with every committed write in the namespace and is **never reused**, not even after a rollback. A plain counter
 *   is not enough: a rolled-back increment is handed out again by the next write, so a value read inside the
 *   rolled-back transaction could match it. Combine a counter with a random part, or use a random token. The token
 *   is written in the same transaction as the value. A namespace that was never written returns a constant token.
 * - **Committed data only.** Reads see committed data; the resolver may cache what they return under the token.
 * - **Plain strings.** {@see read()} returns the stored strings, decrypted; null when the setting is unset. It may
 *   omit an expired previous value; the resolver ignores one anyway.
 * - **Failures.** Any exception means "cannot read now"; the resolver applies the setting's `on_error`. Never put a
 *   value in an exception message.
 * - **Worker mode.** No per-request state: one instance serves every request of a long-running worker.
 */
interface RuntimeValueSourceInterface
{
    public function namespaceVersion(string $namespace): string;

    public function read(RuntimeSettingId $id): ?StoredRuntimeValue;
}
