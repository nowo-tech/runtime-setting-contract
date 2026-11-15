# Runtime Setting Contract

[![CI](https://github.com/nowo-tech/runtime-setting-contract/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/runtime-setting-contract/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/runtime-setting-contract.svg?style=flat)](https://packagist.org/packages/nowo-tech/runtime-setting-contract) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/runtime-setting-contract.svg)](https://packagist.org/packages/nowo-tech/runtime-setting-contract) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.4%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/runtime-setting-contract.svg?style=social&label=Star)](https://github.com/nowo-tech/runtime-setting-contract) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Install it from [Packagist](https://packagist.org/packages/nowo-tech/runtime-setting-contract) and star [runtime-setting-contract](https://github.com/nowo-tech/runtime-setting-contract).

A bundle setting that lives in `.env` today can come from the **database** instead, change **live** through a console
command (or an admin UI), and **rotate** without downtime. This package is the contract every bundle and backend
shares: a framework-free port and resolver (PHP and PSR interfaces only), a versioned cache that reaches every worker
within `version_ttl` without a restart, one set of write rules, and an optional Symfony bridge.

> The contract has no Symfony or Doctrine dependency. The bridge supports Symfony 7.4, 8.0 and 8.1. PHP 8.2+ (Symfony 8.x requires PHP 8.4+).

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is **FrankenPHP worker mode friendly**, including when `reset_kernel` is false: one value per request,
memo cleared at every boundary, bounded state. See [FrankenPHP worker audit](docs/FRANKENPHP-WORKER-AUDIT.md).

## Table of contents

- [Features](#features)
- [Installation](#installation)
- [Requirements](#requirements)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Console commands](#console-commands)
- [Documentation](#documentation)
- [Tests and coverage](#tests-and-coverage)
- [License](#license)

## Features

- **Per-setting source**: `env` (default, today's behaviour), `db`, or `db_then_env`, chosen by the host.
- **Fail closed by default**: `on_error: fail_closed` aborts when the source is down or holds an invalid value;
  `fallback` warns and uses the env value.
- **Validation everywhere**: the bundle's validator runs on env, database, cached and command values; values are
  bounded before parsing.
- **Live change without restart**: a per-namespace version token, read at most once per request and reused for at
  most `version_ttl`, invalidates an optional shared PSR-6 cache; no shared Redis needed.
- **Secrets**: never in the shared cache, never logged or printed, only through `--stdin`; no fingerprint of a
  secret is ever produced (audit entries record only that it changed).
- **Bounds are a decision**: a setting that can be written at runtime needs a validator (`IntRange`,
  `SingleLineString`, your own) or the explicit `Unbounded` opt-out, else the container build fails.
- **Rotation**: current + previous with an expiry; `candidates()` accepts both inside the window.
- **Worker-safe** and **DDD-friendly**: memo cleared at every boundary; `src/Contract` runs without Symfony.
- **Ids** compatible with RuntimeEnvBundle: `namespace.key` or an environment variable name.

## Installation

```bash
composer require nowo-tech/runtime-setting-contract
```

Symfony hosts register `Nowo\RuntimeSettingContract\Bridge\Symfony\NowoRuntimeSettingBundle`. See
[Installation](docs/INSTALLATION.md).

## Requirements

- PHP >= 8.2, < 8.6
- `psr/clock`, `psr/log`, `psr/event-dispatcher` (interfaces)
- Optional: Symfony 7.4 or 8.x for the bridge, `psr/cache` for the shared cache

## Quick start

```php
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\Validator\IntRange;

$retention = new RuntimeSettingDefinition(
    'CHAT_RETENTION_DAYS',                  // or 'chat.retention_days'
    RuntimeSettingType::Int,
    RuntimeSettingSource::DbThenEnv,
    validator: new IntRange(1, 36500),       // required when the source can be written (or Unbounded explicitly)
    configured: $_ENV['CHAT_RETENTION_DAYS'] ?? null,
    default: 90,
);

$days = $resolver->get($retention);         // stored value, else env, else default; validated
```

## Configuration

```yaml
nowo_runtime_setting:
    source: App\Settings\SettingsStore
    writer: App\Settings\SettingsStore
    cache_pool: cache.app
    version_ttl: 5
    command:
        enabled: false

nowo_chat_kit:
    retention_days: '%env(int:CHAT_RETENTION_DAYS)%'
    runtime:
        retention_days: { source: db_then_env, key: CHAT_RETENTION_DAYS, on_error: fail_closed }
```

Full reference: [Configuration](docs/CONFIGURATION.md).

## Console commands

Registered only with `command.enabled: true`:

```bash
bin/console nowo:runtime-setting:list [--namespace=chat]
bin/console nowo:runtime-setting:get <id>
bin/console nowo:runtime-setting:set <id> [<value>] [--stdin] [--dry-run]
bin/console nowo:runtime-setting:unset <id> [--dry-run]
bin/console nowo:runtime-setting:rotate <id> --stdin [--keep-previous=PT1H] [--dry-run]
bin/console nowo:runtime-setting:purge          # delete expired previous values at rest (schedule it)
```

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [FrankenPHP worker audit (`resetKernel=false`)](docs/FRANKENPHP-WORKER-AUDIT.md)
- [GitHub Actions CI requirements](docs/GITHUB_CI.md)
- [PSR evaluation (REQ-CS-007)](docs/PSR.md)

## Tests and coverage

- Tests: PHPUnit under `tests/Unit/` and `tests/Integration/` (Symfony kernel kept alive without the services
  resetter, a second PHP process running the console command, 2,000-request memory test, DDD host without Symfony,
  rollback visibility)
- Coverage:
  - PHP: 100%
  - TS/JS: N/A
  - Python: N/A
- Badge / CI: PHPUnit coverage job on PHP 8.2 + Symfony 7.4

## License

MIT. See [LICENSE](LICENSE).
