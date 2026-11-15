# Baseline specification — Runtime Setting Contract

**Package:** `nowo-tech/runtime-setting-contract`  
**Status:** Implemented (unreleased, 1.0.0 candidate)  
**Last updated:** 2026-11-16  
**Origin:** owner request 2026-11-16, design note `runtime-settings/SPEC.md` (sections 1–7 below)

## Product summary

A bundle setting that lives in `.env` today can come from a database source instead, through a service that reads the
source and may cache the value. A console command, which configuration can turn off, changes it; the running project
picks up the change without a restart, so secrets rotate without downtime. It works under FrankenPHP worker mode with
the kernel not reset, and with DDD hosts (the contract is framework-free).

## User scenarios

### US-01 — Move a setting from env to the database without breaking hosts

- **Given** a bundle registers `chat.retention_days` with the default `source: env`
- **When** the host does nothing
- **Then** the configured env value is used and nothing reads the database (FR-RES-001)

### US-02 — Database first, env fallback

- **Given** `source: db_then_env`
- **When** the setting is unset in the source
- **Then** the configured env value, then the default, is used (FR-RES-002)

### US-03 — Change it live

- **Given** a worker kernel kept alive and `version_ttl: 1`
- **When** another process runs `nowo:runtime-setting:set chat.retention_days 45`
- **Then** the worker serves 45 within one second, without a restart (FR-RES-004, FR-CMD-001)

### US-04 — Rotate a secret without downtime

- **Given** `API_TOKEN` is a secret with `source: db`
- **When** `printf '%s' "$NEW" | bin/console nowo:runtime-setting:rotate API_TOKEN --stdin --keep-previous=PT1H`
- **Then** `candidates()` returns the new and the old value for one hour, then only the new one (FR-RES-006, FR-UPD-004)

### US-05 — Database down

- **Given** the source throws
- **Then** `fail_closed` aborts the operation, `fallback` warns (id only) and uses the env value (FR-RES-005)

### US-06 — DDD host

- **Given** a host without Symfony, InstanceSettings or RuntimeEnv
- **When** it implements the source and writer over its own repository
- **Then** the resolver and updater work unchanged and the domain sees only its typed port (FR-ARCH-001)

## Functional requirements

| ID | Requirement |
|---|---|
| FR-ID-001 | Ids are `namespace.key` or an env variable name (`env` namespace), ASCII, matched byte for byte, bounded, `/D` patterns; malformed ids are refused without echo. |
| FR-TYPE-001 | Values are strings in the source, parsed strictly by type (string UTF-8, int in range, finite float, bool), bounded before parsing. |
| FR-DEF-001 | A definition carries id, type, source, `on_error` (default `fail_closed`), secret flag, validator, configured value, default, required, max length; invalid configured values or defaults are configuration errors. |
| FR-DEF-002 | The validator runs on every value; anything it throws refuses the value; its result is re-checked against type and bound. |
| FR-REG-001 | The registry holds the registered definitions, refuses duplicates and unknown ids. |
| FR-RES-001 | `source: env` never reads the source. |
| FR-RES-002 | `db` uses the source, else the default, else fails when required; `db_then_env` uses the source, else configured, else default. |
| FR-RES-003 | One value per setting per request (memo), cleared by `clearRuntimeCache()` / `reset()`. |
| FR-RES-004 | The namespace version token is read at most once per request and reused for at most `version_ttl` (0–300 s). |
| FR-RES-005 | Source failures and invalid stored values follow `on_error`; messages and logs carry the id only. |
| FR-RES-006 | `candidates()` returns current, then previous while its window is open; an invalid previous value is dropped. |
| FR-RES-007 | Optional shared cache keyed by version token; secrets never cached; cache failures are misses. |
| FR-RES-008 | All resolver state is bounded (1,024 entries per map). |
| FR-UPD-001 | Writes accept registered ids only and refuse `source: env`. |
| FR-UPD-002 | Values are bounded, parsed and validated before writing; the validated value is stored. |
| FR-UPD-003 | Dry runs write nothing. |
| FR-UPD-004 | Rotation windows are 0 s to 30 days. |
| FR-UPD-005 | After every write (committed or not) the namespace is forgotten; `SettingChangedEvent` (ids only) only after the writer returned; a failing listener is logged. |
| FR-SRC-001 | Source contract: never-reused version tokens bumped in the write transaction; committed reads; plain strings. |
| FR-WRT-001 | Writer contract: own transaction, refuse nested, encrypt secrets, audit with fingerprints, no events. |
| FR-FP-001 | Fingerprints are keyed HMAC-SHA-256 over id and value, key at least 16 bytes. |
| FR-MEM-001 | The in-memory store follows FR-SRC-001 / FR-WRT-001 with a bounded audit trail. |
| FR-SF-001 | Symfony bundle `nowo_runtime_setting`: source, writer, cache pool, cache lifetime, version TTL, fingerprint key, `command.enabled` (default false). |
| FR-SF-002 | Boundary listener: main `kernel.request`, `kernel.terminate`, `console.command`, `console.terminate`, Messenger received / handled / failed; `ResetInterface`. |
| FR-SF-003 | PSR-6 adapter validates entries strictly and matches the version token exactly. |
| FR-SF-004 | Config tree helper and definition registration for adopting bundles. |
| FR-SF-005 | Ports default to the unconfigured store only when nothing else binds them. |
| FR-CMD-001 | Commands get / set / unset / list / rotate; secrets only via `--stdin`, never echoed; actor `cli:<os user>`; `--dry-run`; exit 1 on refusal. |
| FR-ARCH-001 | `src/Contract` and `src/InMemory` import no Symfony or Doctrine. |

## Scope

### In scope

The contract, the resolver, the updater, the reference store, the Symfony bridge and the commands.

### Explicit non-goals

- A database adapter (RuntimeEnvBundle 1.5.0 and InstanceSettingsBundle 1.20.0 ship them).
- An admin UI (backends ship it, through `RuntimeSettingUpdater`).
- Settings that must stay env (see [CONFIGURATION.md](../../docs/CONFIGURATION.md#what-cannot-move-to-the-database)).
