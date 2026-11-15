# Spec-driven development

## Table of contents

- [Purpose](#purpose)
- [Product layers](#product-layers)
- [User stories](#user-stories)
- [Functional scope](#functional-scope)
- [Public API (Packagist contract)](#public-api-packagist-contract)
- [Validating the functional spec](#validating-the-functional-spec)
- [Requirement identifiers (Makefile)](#requirement-identifiers-makefile)
- [Contributor workflow](#contributor-workflow)
- [Relationship with Engram](#relationship-with-engram)
- [GitHub Spec Kit](#github-spec-kit)
  - [Spec Kit workflow (summary)](#spec-kit-workflow-summary)
- [See also](#see-also)

## Purpose

This document describes **what Runtime Setting Contract guarantees**, how behavior is proven, and how Spec Kit /
Engram fit into the maintainer workflow.

## Product layers

1. **GitHub Spec Kit baseline** — [`specs/001-baseline/`](../specs/001-baseline/) and operator manual [`SPEC-KIT.md`](SPEC-KIT.md).
2. **Integrator contract** — the interfaces and classes in `src/Contract` (normative docblocks), the Symfony bridge
   configuration `nowo_runtime_setting`, and the `runtime:` node adopting bundles expose.
3. **Repo `REQ-*` traceability** — Makefile and CI. This package has no demos.

Mechanical proof is **PHPUnit** + **PHPStan** (+ Igor for worker state).

## User stories

| ID | Intent | Scope / docs |
| -- | ------ | ------------ |
| US-01 | Keep today's env behaviour by default | `source: env` · [CONFIGURATION](CONFIGURATION.md) |
| US-02 | Read a setting from the database first, env as fallback | `db_then_env` · [CONFIGURATION](CONFIGURATION.md) |
| US-03 | Change a setting live from the console | `nowo:runtime-setting:set` · [USAGE](USAGE.md) |
| US-04 | Rotate a secret without downtime | `rotate`, `candidates()` · [USAGE](USAGE.md) |
| US-05 | Fail closed when the database is down | `on_error` · [CONFIGURATION](CONFIGURATION.md) |
| US-06 | Use it from a DDD host without Symfony | `src/Contract` · [USAGE](USAGE.md#ddd-host-without-symfony) |

## Functional scope

**Goal:** one worker-safe way for bundles to read settings that may live in env or a database, and one set of rules
to change them.

**In scope:** contract, resolver, updater, reference store, Symfony bridge, console commands.

**Explicit non-goals:** database adapters and admin UIs (backend packages), settings that must stay env.

**Not part of the Packagist API:** repository tooling (`Makefile`, `.github/`, `.cursor/`, `.specify/`, `specs/`) and
classes marked `@internal`.

## Public API (Packagist contract)

| Artifact | Responsibility |
| --- | --- |
| `RuntimeValueSourceInterface` / `RuntimeSettingWriterInterface` | Ports a backend implements |
| `RuntimeSettingDefinition`, `RuntimeSettingId`, `RuntimeSettingType`, `RuntimeSettingSource`, `RuntimeSettingErrorPolicy`, `RuntimeSettingValidatorInterface`, `Validator\IntRange`, `Validator\SingleLineString`, `Validator\Unbounded` | What a bundle registers |
| `RuntimeSettingResolver` (`get`, `candidates`, `clearRuntimeCache`, `forgetNamespace`, `reset`) | Reading |
| `RuntimeSettingUpdater` (`writableDefinition`, `prepare`, `set`, `unset`, `rotate`, `purgeExpired`) | Writing, shared by CLI and admin UI |
| `RuntimeSettingRegistry`, `RuntimeValueFingerprinter`, `RuntimeSettingWriteContext`, `RuntimeSettingChange`, `RuntimeSettingAction`, `StoredRuntimeValue`, `RuntimeValueCacheInterface`, `RuntimeValueCacheEntry`, `SettingChangedEvent`, `SystemClock` | Supporting types |
| `Contract\Exception\*` | Failures; messages never contain values |
| `InMemoryRuntimeSettingStore` | Reference store |
| `Psr6RuntimeValueCache` | PSR-6 bridge (`psr/cache` only) |
| `NowoRuntimeSettingBundle`, `RuntimeSettingBoundaryListener`, `RuntimeSettingsConfiguration`, `DefaultPortsPass`, `UnconfiguredRuntimeSettingStore`, the six commands | Symfony bridge |

## Validating the functional spec

| Command | Proves |
| ------- | ------ |
| `make test` / `make test-coverage` | Unit + integration behavior (PHP statements = 100%), including spec §7 |
| `make phpstan` / `make cs-check` / `make rector-dry` / `make igor` | Static quality and worker state |
| `make release-check` | Full pre-release gate, including open-PR and co-author checks |

Behavior changes **require** tests under `tests/`.

## Requirement identifiers (Makefile)

| ID | Location | Meaning |
| -- | -------- | ------- |
| REQ-MAKE-001 | root `Makefile` | `ensure-up`, standard targets, `release-check` |
| REQ-MAKE-002 | root `Makefile` `release-check` | Co-author check, open PRs, style, Rector, PHPStan, Igor, coverage |
| REQ-MAKE-006 | `setup-hooks` | Install `.githooks/commit-msg` |
| REQ-MAKE-008 | `update-deps` | `composer update` in the package container (no demos) |
| REQ-MAKE-009 | `Makefile` | No path outside this git repository |
| REQ-MAKE-010 | `COMPOSE_BIN` | Docker Compose V2 with V1 fallback |
| REQ-GIT-001 | `.githooks/`, `.scripts/check-no-*-coauthor.sh` | No AI co-author trailers |
| REQ-REL-003 | `.scripts/check-open-prs.sh` | No unresolved open pull requests |
| REQ-RUNTIME-002 | FrankenPHP worker, `reset_kernel: false` | Boundary listener; audit in `docs/FRANKENPHP-WORKER-AUDIT.md`; PHPStan worker-strict + hardening; Igor |
| REQ-TEST-003 | `.scripts/coverage-check.sh` | Statement coverage gate |

## Contributor workflow

1. Clarify intent against baseline `FR-*` / user stories.
2. Implement with tests under `tests/Unit` and `tests/Integration`.
3. Keep `specs/001-baseline/code-inventory.md` at 100% of `src/`.
4. Update integrator docs when behavior or config changes.
5. Run `make release-check` before tagging.

## Relationship with Engram

[`ENGRAM.md`](ENGRAM.md) covers Cursor MCP. This file owns **product behavior + local REQ-* traceability**.

## GitHub Spec Kit

Full operator manual: [`SPEC-KIT.md`](SPEC-KIT.md). Baseline: [`specs/001-baseline/spec.md`](../specs/001-baseline/spec.md) + [`code-inventory.md`](../specs/001-baseline/code-inventory.md).

### Spec Kit workflow (summary)

1. Specify / clarify in `specs/`
2. Plan and tasks via Spec Kit skills
3. Implement against `FR-*`
4. Keep inventory at 100% of `src/`
5. Run `make release-check` before tagging

## See also

- [SPEC-KIT.md](SPEC-KIT.md)
- [USAGE.md](USAGE.md)
- [CONFIGURATION.md](CONFIGURATION.md)
- [CONTRIBUTING.md](CONTRIBUTING.md)
- [RELEASE.md](RELEASE.md)
- [SECURITY.md](SECURITY.md)
