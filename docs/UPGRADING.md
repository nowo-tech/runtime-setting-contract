# Upgrading

## Table of contents

- [To 1.1.0](#to-110)
- [To 1.0.0](#to-100)

## To 1.1.0

No action needed: every addition is optional and the 1.0 behaviour is unchanged when no provider is configured
(processed `runtime` nodes keep their 1.0 keys; constructors only gained optional trailing arguments).

To read a setting from a read-only provider (see [CONFIGURATION.md](CONFIGURATION.md#read-only-value-providers-11)):

1. Register the provider: `nowo_runtime_setting.providers.<name>: { service: ..., cache_ttl: ... }`, or a tagged
   service from an adapter bundle (nowo-tech/runtime-env-bundle 1.10 DBAL query provider).
2. Bind the setting: `runtime.<setting>.provider: <name>` in the owning bundle's node, or
   `nowo_runtime_setting.setting_providers.<id>: <name>`. The setting must use `source: db` or `db_then_env`.
3. A provider whose answer depends on the request (tenant, user) must keep `cache_ttl: 0`.

Adapters that decide on `RuntimeSettingDefinition::isWritable()` keep routing provider settings to
`RuntimeSettingUpdater`, which refuses them (`isWritable()` keeps its 1.0 meaning; use `isReadOnly()` to know whether
a write can succeed). Upgrade nowo-tech/runtime-env-bundle to 1.10 for its admin UI to say so before the form is
submitted.

## To 1.0.0

Released 2026-11-16. First release; nothing to upgrade from.

For bundles adopting the pattern (moving a setting from `.env` to a runtime source):

1. Keep the bundle's typed port and its current configuration key; the configured value becomes the env fallback.
2. Register a `RuntimeSettingDefinition` per setting with `RuntimeSettingsConfiguration::registerDefinition()` and add
   the `runtime:` node with `RuntimeSettingsConfiguration::appendTo()`. The default `source: env` keeps today's
   behaviour, so the change is backward compatible for hosts.
3. Move validation and clamping into a `RuntimeSettingValidatorInterface` (or use `IntRange` / `SingleLineString`);
   it now also runs on database values and before command writes. It is **required** as soon as a host can choose
   `db` or `db_then_env`: pass `validatorServiceId` for every setting you register, or `Unbounded` explicitly.
4. Use `fail_closed` as the default `on_error` for settings that delete data or affect security.
5. Add the adopter tests listed in [USAGE.md](USAGE.md#tests-every-adopter-must-have).

The public API listed in the README is frozen for 1.x; see [CONTRIBUTING.md](CONTRIBUTING.md#backward-compatibility).
