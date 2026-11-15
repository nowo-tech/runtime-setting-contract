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
- **Secrets** are only accepted through `--stdin` in the commands, never printed (masked, with a keyed fingerprint),
  never written to the shared pool, never logged; an empty secret is refused.
- **Fingerprints** are HMAC-SHA-256 (truncated to 64 bits) with a host key (`kernel.secret` by default, at least 16
  bytes), over the id and the value, so equal values of two settings differ and a low-entropy secret cannot be
  confirmed without the key.
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
| AI security audit (REQ-SEC-004) | Pending: audit before the 1.0.0 tag |

## AI security audit

Not yet audited. The audit runs before the 1.0.0 tag; its result is recorded here.
