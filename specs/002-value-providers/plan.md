# Implementation plan — 002 value providers

1. Contract (framework-free): `RuntimeValueProviderInterface`, `RuntimeValueProviderBinding`,
   `RuntimeValueProviderRegistry`; exception factories `readOnlyProvider()`, `providerFailed()`, `unknownProvider()`.
2. `RuntimeSettingDefinition`: optional trailing `provider`, `usesProvider()`, `isReadOnly()`; validation with the
   source.
3. `RuntimeSettingResolver`: optional trailing `providers`; provider branch in `fromSource()` (lookup outside the
   failure handling), sanitised failures, size bound, time-bucket token for the shared cache, secrets excluded.
4. `RuntimeSettingUpdater::writableDefinition()`: refuse provider settings.
5. Symfony bridge: bundle options `providers` / `setting_providers`, registry service, `RuntimeValueProvidersPass`,
   optional `provider` node in `appendTo()`, `registerDefinition()` argument, `list` column.
6. Tests: unit (registry, resolution, pass, config helper), kernel integration, standalone script; 100% coverage.
7. Docs: README, CONFIGURATION, USAGE, SECURITY, FRANKENPHP-WORKER-AUDIT, CHANGELOG, UPGRADING; code inventory.
