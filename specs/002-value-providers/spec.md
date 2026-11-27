# Feature specification — Read-only value providers

**Package:** `nowo-tech/runtime-setting-contract`  
**Feature:** `002-value-providers`  
**Target release:** 1.1.0 (minor, no BC break)  
**Status:** Implemented (unreleased)  
**Last updated:** 2026-11-27  
**Origin:** owner request 2026-11-27 — environment variables moved to the database come from pluggable providers that
define which query fetches each variable and its value, with caching.

## Product summary

A setting with `source: db` or `db_then_env` can name a **provider**: a read-only service that fetches its value
(a per-tenant lookup, a join, a parameterised query configured in YAML by nowo-tech/runtime-env-bundle). Without a
provider nothing changes. Providers never write; their values go through the same bounds, type and bundle validator
as any other, are cached per provider TTL in the existing versioned PSR-6 cache (never secrets), and their failures
follow `on_error` without leaking SQL or values.

## User scenarios

### US-01 — Host keeps 1.0 behaviour

- **Given** a 1.0 host and 1.0 bundles upgrade the contract to 1.1
- **When** they configure nothing new
- **Then** processed configuration, resolution, writes and commands are identical (FR-PROV-009)

### US-02 — Per-tenant value from the host's own table

- **Given** a host provider `tenant_settings` implementing `RuntimeValueProviderInterface`
- **When** `nowo_runtime_setting.setting_providers: { chat.retention_days: tenant_settings }`
- **Then** `RuntimeSettingResolver::get()` returns the provider's value, validated by ChatKit's validator (FR-PROV-003)

### US-03 — Writes are refused

- **Given** a provider-backed setting
- **When** an operator runs `nowo:runtime-setting:set chat.retention_days 5` or an admin UI submits a value
- **Then** `RuntimeSettingNotWritable` ("read from the read-only provider") and nothing is written (FR-PROV-005)

### US-04 — Provider down

- **Given** the provider throws an exception whose message quotes its SQL
- **Then** `fail_closed` throws `RuntimeSettingUnavailable` whose previous is a `RuntimeSettingSourceFailure` with the
  provider name and exception class only; `fallback` logs the id and that class and uses the configured value
  (FR-PROV-004)

### US-05 — Cached across workers

- **Given** a provider with `cache_ttl: 60` and a `cache_pool`
- **Then** a non-secret value is read at most once per 60-second bucket across workers; a secret is read once per
  request and never reaches the pool (FR-PROV-006)

### US-06 — Wiring mistakes fail the build

- **Given** a setting naming an unregistered provider, a provider on `source: env`, a mapped id no bundle registered,
  or two providers with one name
- **Then** the container build fails with `RuntimeSettingConfigurationError` (FR-PROV-007, FR-PROV-008)

## Functional requirements

| ID | Requirement |
|---|---|
| FR-PROV-001 | `RuntimeValueProviderInterface::read(RuntimeSettingId): ?StoredRuntimeValue` — read-only, committed data, at most one row, plain strings, no per-request state, never a value in an exception message. Framework-free. |
| FR-PROV-002 | `RuntimeValueProviderBinding(name, provider, cacheTtl)`: name `[a-z][a-z0-9_]{0,63}`, TTL 0..3600 s; `RuntimeValueProviderRegistry` refuses duplicate names, finds by name, echoes an unknown name only when well formed. |
| FR-PROV-003 | `RuntimeSettingDefinition` gains an optional trailing `provider` (validated name; refused with `source: env`); validator still mandatory for `db` / `db_then_env`. The resolver reads the provider instead of the source (no namespace version read); `db` / `db_then_env` fallbacks, `required`, rotation candidates and validation are unchanged. |
| FR-PROV-004 | A provider exception becomes `RuntimeSettingSourceFailure::providerFailed()` (name + exception class, no message, no chain), then `on_error` applies. A current or previous value over 65,536 bytes is a source failure before parsing. An unregistered provider is a `RuntimeSettingConfigurationError`, never hidden by `fallback`. |
| FR-PROV-005 | `RuntimeSettingUpdater::writableDefinition()` (hence `set`, `unset`, `rotate`, `prepare`, dry runs, commands and adapter UIs that use the updater) refuses provider settings with `RuntimeSettingNotWritable::readOnlyProvider()`. `isWritable()` keeps its 1.0 meaning (source kind) so 1.0 adapters keep routing those writes to the updater; `isReadOnly()` tells whether a write can succeed. |
| FR-PROV-006 | Per-request memo always. Shared cache only for non-secret values of providers with `cacheTtl` > 0, under the token `provider:<name>:<ttl>[:<sha256(cacheScope)[0..16]>]:<floor(now / ttl)>` (same bucket in every process; audit ZDA-L2: an optional binding scope of at most 4096 bytes, hashed, separates per-connection / per-deployment answers). Secrets never reach the shared cache. |
| FR-PROV-007 | Symfony bridge: `providers.<name>: { service, cache_ttl }`, tag `nowo_runtime_setting.provider` (`name`, `cache_ttl`), `setting_providers: { <id>: <name> }`, optional `provider` key in `RuntimeSettingsConfiguration::appendTo()` (absent from the processed node unless set) and `registerDefinition()`. |
| FR-PROV-008 | `RuntimeValueProvidersPass` builds the registry and fails the build on: invalid tag name or TTL, duplicate names, provider on `source: env`, bundle option and map naming different providers, unregistered provider, mapped id not registered. |
| FR-PROV-009 | No BC break: new constructor arguments are optional and trailing; 1.0 processed configuration is unchanged; `nowo:runtime-setting:list` shows `<source> via <provider>` for provider settings only. |

## Success criteria

- **SC-001**: `composer qa` green with 100% line and method coverage; PHPStan level max; Rector; Igor (worker mode).
- **SC-002**: `tests/Standalone/contract-without-symfony.php` resolves a provider setting with `composer install --no-dev`.
- **SC-003**: `ProviderKernelTest` (kept-alive kernel): host map and tagged provider resolve, the next request re-reads, `set` refuses, `list` shows the provider.
- **SC-004**: No test observes a provider's message, SQL or value in an exception message, a chained exception or a log record.

## Key entities

- **Provider** — named read-only source of values for the settings bound to it (FR-PROV-001).
- **Provider binding** — name, provider, shared-cache TTL (FR-PROV-002).
- **Provider token** — time-bucket version under which shared-cache entries of a provider are valid (FR-PROV-006).

## Assumptions

- A provider whose answer depends on the request or the connection (tenant, user, RLS session variable, `search_path`)
  is registered with `cache_ttl: 0`; per-deployment constants sharing one pool use distinct `cache_scope` values
  (documented in CONFIGURATION and SECURITY, audit ZDA-L2).
- Providers run with a database user that can only `SELECT` (recommended; the contract cannot enforce it).

## Scope

### In scope

Provider port, registry, resolver and updater wiring, per-provider TTL, Symfony bridge configuration and checks, docs.

### Explicit non-goals

- A concrete SQL provider (nowo-tech/runtime-env-bundle 1.10 ships the DBAL query provider).
- Writing through providers; a new `RuntimeSettingSource` case.
- Per-tenant keys in the shared cache.

## Validation

```bash
make release-check   # or, without Docker: composer qa
```
