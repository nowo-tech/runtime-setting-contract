# Installation

## Table of contents

- [Requirements](#requirements)
- [Contract only (any PHP host, DDD)](#contract-only-any-php-host-ddd)
- [Symfony host](#symfony-host)
- [Choosing a source](#choosing-a-source)

## Requirements

- PHP >= 8.2, < 8.7.
- Runtime dependencies: `psr/clock`, `psr/log`, `psr/event-dispatcher` (interfaces only).
- For the Symfony bridge: Symfony 7.4 or 8.x (`symfony/http-kernel`, `symfony/dependency-injection`, `symfony/config`,
  and `symfony/console` for the commands). Older Symfony versions are refused through `conflict`.

## Contract only (any PHP host, DDD)

```bash
composer require nowo-tech/runtime-setting-contract
```

Bundles that adopt the pattern require this package and register `RuntimeSettingDefinition`s. A host without
Symfony builds `RuntimeSettingResolver` and `RuntimeSettingUpdater` itself, over its own
`RuntimeValueSourceInterface` / `RuntimeSettingWriterInterface`; see [USAGE.md](USAGE.md#ddd-host-without-symfony).

## Symfony host

Register the bundle (Flex does not do it for a library):

```php
// config/bundles.php
return [
    // ...
    Nowo\RuntimeSettingContract\Bridge\Symfony\NowoRuntimeSettingBundle::class => ['all' => true],
];
```

Then point it at a source and a writer, see [CONFIGURATION.md](CONFIGURATION.md). Without them, every setting that is
not `source: env` fails to read (its `on_error` decides) and writes are refused.

## Choosing a source

| Backend | Package | Notes |
|---|---|---|
| One encrypted table keyed by environment variable name | [`nowo-tech/runtime-env-bundle`](https://github.com/nowo-tech/RuntimeEnvBundle) (adapter planned for 1.5.0) | The main backend for moving `.env` values to the database. |
| Admin settings sections | [`nowo-tech/instance-settings-bundle`](https://github.com/nowo-tech/InstanceSettingsBundle) (adapter planned for 1.20.0) | For hosts such as Beacon that keep settings in admin sections. |
| Your own repository | your code | Implement the two ports; follow the contract in their docblocks. |
| Memory | `InMemoryRuntimeSettingStore` (this package) | Tests, examples, values set at boot. |
