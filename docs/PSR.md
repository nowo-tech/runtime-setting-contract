# PHP-FIG PSR evaluation (REQ-CS-007)

Package: `nowo-tech/runtime-setting-contract` (`library`)

This document records which [PHP-FIG PSRs](https://www.php-fig.org/psr/) apply to this package.
Only contracts that add clear interoperability or maintainability value are **Adopted**.
Others are **N/A**.

## Table of contents

- [Baseline (always)](#baseline-always)
- [Interface / contract PSRs](#interface--contract-psrs)
- [Summary](#summary)

## Baseline (always)

| PSR | Decision | How |
| --- | -------- | --- |
| PSR-12 (coding style) | **Adopted** | `@PSR12` in `.php-cs-fixer.dist.php` (REQ-CS-001). |
| PSR-4 (autoloading) | **Adopted** | `composer.json` `autoload` / `autoload-dev` PSR-4 maps. |

## Interface / contract PSRs

| PSR | Decision | Notes |
| --- | -------- | ----- |
| PSR-3 Logger | **Adopted** | `RuntimeSettingResolver`, `RuntimeSettingUpdater` and `Psr6RuntimeValueCache` take an optional `Psr\Log\LoggerInterface` (ids and exception classes only, never values). `psr/log` is required. |
| PSR-6 Cache | **Adopted (optional)** | `Psr6RuntimeValueCache` wraps any `CacheItemPoolInterface` for non-secret values. `psr/cache` is suggested, not required. |
| PSR-16 Simple cache | **N/A** | PSR-6 covers the shared cache; Symfony pools implement it. |
| PSR-7 / PSR-17 HTTP messages | **N/A** | No HTTP. |
| PSR-11 Container | **N/A** | Constructor injection only. |
| PSR-14 Event dispatcher | **Adopted** | `RuntimeSettingUpdater` dispatches `SettingChangedEvent` through an optional `Psr\EventDispatcher\EventDispatcherInterface` (Symfony's dispatcher implements it). `psr/event-dispatcher` is required. |
| PSR-15 HTTP middleware | **N/A** | No HTTP stack. |
| PSR-18 HTTP client | **N/A** | No HTTP client. |
| PSR-20 Clock | **Adopted** | Version TTL and rotation windows read `Psr\Clock\ClockInterface` (`SystemClock` by default); tests inject a fixed clock. `psr/clock` is required. |

## Summary

- **Adopted beyond baseline:** PSR-3, PSR-14 and PSR-20 (required, interfaces only) and PSR-6 (optional adapter).
- **Rule:** do not add `psr/*` Composer dependencies without matching type-hints and DI wiring.

_REQ-CS-007 evaluation date: 2026-11-16._
