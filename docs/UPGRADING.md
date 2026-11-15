# Upgrading

## Table of contents

- [To 1.0.0](#to-100)

## To 1.0.0

First release; nothing to upgrade from.

For bundles adopting the pattern (moving a setting from `.env` to a runtime source):

1. Keep the bundle's typed port and its current configuration key; the configured value becomes the env fallback.
2. Register a `RuntimeSettingDefinition` per setting with `RuntimeSettingsConfiguration::registerDefinition()` and add
   the `runtime:` node with `RuntimeSettingsConfiguration::appendTo()`. The default `source: env` keeps today's
   behaviour, so the change is backward compatible for hosts.
3. Move validation and clamping into a `RuntimeSettingValidatorInterface`; it now also runs on database values and
   before command writes.
4. Use `fail_closed` as the default `on_error` for settings that delete data or affect security.
5. Add the adopter tests listed in [USAGE.md](USAGE.md#tests-every-adopter-must-have).

The public API listed in the README is frozen for 1.x; see [CONTRIBUTING.md](CONTRIBUTING.md#backward-compatibility).
