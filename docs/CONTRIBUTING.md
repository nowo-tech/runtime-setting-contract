# Contributing

Thanks for helping. This package is a contract first: small, framework-free, stable.

## Table of contents

- [What belongs here](#what-belongs-here)
- [Getting started](#getting-started)
- [Quality gates](#quality-gates)
- [Backward compatibility](#backward-compatibility)
- [Commits and pull requests](#commits-and-pull-requests)

## What belongs here

- `src/Contract`: ports, value objects, the resolver and the updater. PHP and PSR interfaces only: no Symfony, no
  Doctrine (CI runs it with `composer install --no-dev`).
- `src/InMemory`: the reference store.
- `src/Bridge/Symfony`: optional Symfony glue.

Database adapters live in the backend packages (RuntimeEnvBundle, InstanceSettingsBundle) or in the host.

## Getting started

```bash
make setup-hooks
make up
make test
make release-check
```

Without Docker: `composer install` then `composer qa`.

## Quality gates

- `composer cs-check` / `composer cs-fix`: PHP-CS-Fixer.
- `composer phpstan`: level max, FrankenPHP worker rulesets, empty `ignoreErrors`.
- `composer test`: PHPUnit; coverage must stay at 100% of lines and methods (CI enforces it).
- `composer rector-dry`: no pending changes.
- `composer igor`: worker-state audit exits 0.
- `composer validate --strict`.

## Backward compatibility

Every interface here is implemented by packages released independently:

- Adding a method to `RuntimeValueSourceInterface`, `RuntimeSettingWriterInterface`,
  `RuntimeSettingValidatorInterface` or `RuntimeValueCacheInterface` is a **major** change.
- Tightening the contract in an interface docblock is a major change.
- Adding an optional constructor argument at the end of a class is a minor change; a required one is major.
- Removing or renaming anything public, or a configuration key, is a major change.

## Commits and pull requests

- Conventional commit subjects (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`).
- No AI co-author trailers of any kind (REQ-GIT-001).
- One topic per pull request, with the changelog entry in the same pull request.
- `main` is protected: pull request plus green CI, no force-push, no deletion (REQ-GIT-002).
