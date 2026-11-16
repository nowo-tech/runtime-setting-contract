# Security

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Mitigations](#mitigations)
- [What the host and adapters must still do](#what-the-host-and-adapters-must-still-do)
- [Secrets and cryptography](#secrets-and-cryptography)
- [Logging](#logging)
- [Dependencies and updates](#dependencies-and-updates)
- [Permissions and exposure](#permissions-and-exposure)
- [Reporting](#reporting)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit](#ai-security-audit)
  - [2026-11-15 audit QS](#2026-11-15-audit-qs)

## Scope

This package resolves runtime settings (from a source, the configured env value or a default), validates them, and
changes them through one updater shared by the console commands and admin UIs. It stores nothing itself (except the
in-memory reference store) and opens no network connection.

## Attack surface

- Setting ids given to the registry, the updater and the commands (`namespace.key` or an env variable name).
- Values given to the updater: command arguments, STDIN, admin form input.
- Values read from the source, and entries read from the shared PSR-6 pool (another process, or anyone with write
  access to the pool, may have put them there).
- Bridge configuration: `source`, `writer`, `cache_pool`, `version_ttl`, `fingerprint_key`, `command.enabled`.
- The console commands, registered only with `command.enabled: true`.
- No HTTP routes, forms or templates.

## Threat model

| Threat | Risk |
| --- | --- |
| Secret leaked through shell history or `ps` when passed as an argument | High without the `--stdin` rule |
| Secret leaked through logs, exception messages, command output, audit entries or the shared cache | High |
| Absurd or hostile value (huge integer, megabytes of text) deleting data or exhausting memory (lesson QP-H-1) | High for settings that delete data |
| Poisoned or malformed entry in a shared cache served as a setting | Medium |
| Value from a rolled-back write served or announced (phantom change, lessons QL-M1 / QL-M2, IS R10) | Medium |
| Value from one request served to another in a long-running worker | Medium |
| Look-alike ids (case, whitespace, trailing newline, NBSP) addressing another setting (REQ-SEC-006) | Medium |
| Writing a setting the host meant to keep in the environment | Medium |
| SQL injection, XSS, CSRF, deserialization of untrusted classes | Not applicable here: no SQL, no HTML, no session; adapters own their storage |

## Mitigations

- **Ids** are ASCII-only, matched byte for byte, length-checked before any pattern; patterns end in `$/D`. Malformed
  ids are refused without echoing them.
- **Values** are bounded (65,536 bytes, or the definition's `maxLength`) before parsing; integers out of range are
  refused, never saturated; floats must be finite; strings must be valid UTF-8; booleans accept `true/false/1/0`
  only. The bundle's validator runs on every value, whatever its origin, and its result is re-checked against the
  type and the bound. A validator that throws refuses the value (fail closed).
- **`on_error: fail_closed`** is the default.
- **Secrets** are only accepted through `--stdin` in the commands, never printed (masked), never written to the
  shared pool, never logged; an empty secret is refused. **No fingerprint of a secret is ever produced**: not by
  `get` or `list`, not by a dry run, not in the updater's result, not in audit entries (which record only that the
  secret changed). A fingerprint, even keyed, lets anyone who may write the secret confirm guesses of its current
  value through a dry run (audit QS-M-1).
- **Fingerprints** of non-secret values are HMAC-SHA-256 (truncated to 64 bits) over the id and the value, with a key
  derived from the host secret (`kernel.secret` or `fingerprint_key`, at least 16 bytes) and the label
  `nowo.runtime-setting.fingerprint.v1`, so they never share a key with the host's other uses of that secret.
- **Bounds are mandatory** for settings that can be written: a definition with `source: db` / `db_then_env` and no
  validator fails at container build (`IntRange`, `SingleLineString` or an explicit `Unbounded`).
- **Validator errors** are never chained: a foreign exception becomes a fixed reason with its class name only, and a
  secret's validator reason is replaced by a generic one, so no value reaches logs through `getPrevious()`.
- **Rotation**: a previous value is never accepted after its expiry; it is deleted at rest by the next write in its
  namespace and by `nowo:runtime-setting:purge`.
- **STDIN** input over the limit is refused, never truncated.
- **Cache entries** are trusted only for the exact version token the source returned, and are validated strictly
  (shape, types, lengths) before use; anything else is a miss.
- **Writes** go through `RuntimeSettingUpdater` only: registered ids, never `source: env`, validated first,
  `--dry-run` available. The process forgets what it remembered about the namespace after every write, committed or
  not, and dispatches `SettingChangedEvent` only after the writer returned. Writers must refuse to run inside a
  caller's transaction and must never reuse a version token.
- **Worker mode**: the per-request memo is cleared at every boundary; all state is bounded
  ([FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)).
- **Commands** are off by default (`command.enabled: false`) and write an audit entry with the actor `cli:<os user>`.

## What the host and adapters must still do

- Restrict who can run `bin/console` in production, and turn the commands on deliberately.
- Restrict write access to the shared cache pool; entries are validated but a pool writer can still serve a stale,
  type-valid non-secret value until the version changes.
- Adapters: encrypt secrets at rest, compare ids exactly (binary / NO PAD collation plus `===` on fetched rows), keep
  the version bump and the audit entry in the write transaction, and refuse nested transactions.
- Admin UIs: protect the form with authentication, authorization and CSRF, and pass `user:<id>` as the actor.
- Keep the values listed in [CONFIGURATION.md](CONFIGURATION.md#what-cannot-move-to-the-database) in the environment.

## Secrets and cryptography

The package stores no secret and implements no encryption. The only cryptographic primitive used is `hash_hmac`
(SHA-256) for fingerprints. Do not put real tokens in committed configuration.

## Logging

Logs carry the setting id, a reason or the exception class, never a value. The source's own exception (which may
contain SQL or a DSN) stays in `getPrevious()` of `RuntimeSettingUnavailable`, for the host's logs only; do not render
it to clients.

## Dependencies and updates

Runtime dependencies are the PSR interface packages `psr/clock`, `psr/log` and `psr/event-dispatcher`. Symfony and
`psr/cache` are optional (bridge). `composer audit` runs in CI; Dependabot updates Composer and GitHub Actions.

## Permissions and exposure

No endpoints. The console commands are the only entry point the package adds, and only when enabled. Access control
is the host's.

## Reporting

Report vulnerabilities to **hectorfranco@nowo.tech**. Do not open a public issue for an unfixed vulnerability. See also
[.github/SECURITY.md](../.github/SECURITY.md).

## Release security checklist (12.4.1)

Confirm before each tag:

| Item | Status |
| --- | --- |
| `docs/SECURITY.md` and `.github/SECURITY.md` exist and stay in English | Required |
| `.env` is gitignored; no secrets in the tree (tests use `REDACTED-TEST` markers) | Required |
| Safe defaults: `command.enabled: false`, `on_error: fail_closed`, `source: env` | Required |
| Input bounded and validated (ids, values, rotation window) | Required |
| `composer audit` reviewed in CI | Required |
| No value in logs, exceptions or command output | Required |
| No custom cryptography (HMAC from ext-hash only) | Required |
| No public endpoints | N/A |
| FrankenPHP worker (`reset_kernel: false`) audit | See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) |
| AI security audit (REQ-SEC-004) | Audited (QS + Delta QS, 2026-11-16, `1e53370`): taggable, no open High/Medium |

## AI security audit

### 2026-11-15 audit QS

Static and dynamic audit of PR #1 at `cfb2500` (first release, no GHSA). Not taggable as audited; every finding below
is fixed in the same PR, each with a regression test (`tests/Unit/Audit/AuditQsRegressionTest.php`). The Delta QS
re-audit (2026-11-16, `1e53370`) found 1.0.0 taggable, with no open High or Medium finding.

| ID | Severity | Finding | Fix |
| --- | --- | --- | --- |
| QS-M-1 | Medium | A dry run returned the fingerprint of a candidate secret: a guess oracle for anyone allowed to write it. | No fingerprint of a secret anywhere (updater result, dry run, `get`, audit entries). |
| QS-M-2 | Medium | A writable setting without a validator accepted any value of its type (QP-H-1 pattern). | A validator is required for `db` / `db_then_env`; `IntRange`, `SingleLineString`, explicit `Unbounded`. |
| QS-L-1 | Low | A source whose value read lags its version read poisons the shared cache. | Source contract: never older than the token; no identity map; same node for token and value. |
| QS-L-2 | Low | Validator exceptions were chained, so a quoted value reached the logs. | Never chained; generic reason for secrets. |
| QS-L-3 | Low | Fingerprint HMAC used `kernel.secret` without domain separation. | Subkey derived with the label `nowo.runtime-setting.fingerprint.v1`. |
| QS-L-4 | Low | Expired previous values stayed at rest without another write. | `purgeExpired()` on the writer port, the updater and `nowo:runtime-setting:purge`; every write purges its namespace. |
| QS-L-5 | Low | README banner path broken (REQ-DOCS-017). | `docs/images/frankenphp-friendly.png`. |
| QS-I-2 / -3 / -4 / -6 | Info | Long-running loops; `env.NAME` spelling; silent STDIN truncation; lossless id mapping. | Documented; `env.NAME` refused; STDIN overflow refused; documented in the ports. |
