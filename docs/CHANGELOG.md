# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)

## [Unreleased]

### Added

- **Contract** (`Nowo\RuntimeSettingContract\Contract`, PHP and PSR interfaces only): `RuntimeValueSourceInterface`,
  `RuntimeSettingWriterInterface` (set, unset, rotate with keep-previous), `RuntimeSettingDefinition` (id, type,
  source `env|db|db_then_env`, `on_error` `fallback|fail_closed`, secret flag, validator, configured value, default,
  required, max length), `RuntimeSettingId` (`namespace.key` or a bare environment variable name, `env.NAME` refused, matched byte for byte),
  `RuntimeSettingType`, `RuntimeSettingRegistry`, `StoredRuntimeValue`, `RuntimeSettingChange`,
  `SettingChangedEvent` (ids only), `RuntimeValueFingerprinter` (keyed HMAC), `RuntimeValueCacheInterface` and the
  exceptions under `Contract\Exception`.
- **Bounds are mandatory for writable settings**: a definition with `source: db` / `db_then_env` and no validator is
  refused (at construction and in `registerDefinition()`). Validators `Contract\Validator\IntRange` (refuse or clamp),
  `SingleLineString` (no control characters, bounded) and the explicit opt-out `Unbounded`.
- **No fingerprint of a secret**: dry runs, updater results, `get` and writer audit entries carry none
  (`RuntimeSettingWriteContext::fingerprint()` returns null for secrets); fingerprints of other values use a key derived
  from the host secret with the label `nowo.runtime-setting.fingerprint.v1`.
- **Purge**: `RuntimeSettingWriterInterface::purgeExpired()`, `RuntimeSettingUpdater::purgeExpired()` and the
  `nowo:runtime-setting:purge` command delete expired previous values at rest; every write purges its namespace.
- **`RuntimeSettingResolver`**: per-request memo cleared at every boundary (`clearRuntimeCache()`, `reset()`),
  namespace version token read at most once per request and reused for at most `version_ttl` seconds, optional shared
  cache keyed by version (never for secrets), validation on every value, `candidates()` for rotation (current plus
  previous inside the window), bounded state.
- **`RuntimeSettingUpdater`**: the rules shared by the commands and admin UIs (registered ids only, `source: env`
  refused, bounded and validated before writing, dry run, rotation window 0 to 30 days, memo dropped after every write,
  `SettingChangedEvent` after commit only).
- **`InMemoryRuntimeSettingStore`**: reference source and writer (never-reused version tokens, rotation, purge of
  expired previous values, bounded audit trail without values).
- **PSR-6 bridge** (`Bridge\Psr6`, optional, `psr/cache` only): `Psr6RuntimeValueCache`, the shared cache of
  non-secret values keyed by version token, with strict entry validation.
- **Symfony bridge** (`Bridge\Symfony`, optional, Symfony 7.4 and 8.x): `NowoRuntimeSettingBundle`,
  `RuntimeSettingBoundaryListener` (main `kernel.request`, `kernel.terminate`, `console.command`,
  `console.terminate`, Messenger worker events, `kernel.reset`),
  `RuntimeSettingsConfiguration` (config tree helper for `runtime: { <setting>: { source, key, on_error } }` and
  definition registration), and the commands `nowo:runtime-setting:get|set|unset|list|rotate|purge`, registered only with
  `command.enabled: true` (default false). Secrets only through `--stdin`, never echoed; audit actor `cli:<os user>`;
  `--dry-run`.

### Documentation

- README and the base documents in `docs/` (REQ-DOCS-003), FrankenPHP worker audit, PSR evaluation, Spec Kit baseline
  (`specs/001-baseline`).

[Unreleased]: https://github.com/nowo-tech/runtime-setting-contract/commits/main
