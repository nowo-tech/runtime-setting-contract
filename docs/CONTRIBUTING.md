# Contributing

Thanks for helping. This package is a contract first: small, framework-free, stable.

## Table of contents

- [Code of Conduct](#code-of-conduct)
- [What belongs here](#what-belongs-here)
- [Getting started](#getting-started)
- [Quality gates](#quality-gates)
- [Backward compatibility](#backward-compatibility)
- [Commits and pull requests](#commits-and-pull-requests)
- [Git hooks (REQ-GIT-001)](#git-hooks-req-git-001)
- [Cursor Agent](#cursor-agent)

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## What belongs here

- `src/Contract`: ports, value objects, the resolver and the updater. PHP and PSR interfaces only: no Symfony, no
  Doctrine (CI runs it with `composer install --no-dev`).
- `src/InMemory`: the reference store.
- `src/Bridge/Psr6`: optional PSR-6 shared cache (`psr/cache` only).
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

## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers, nor `Co-Authored-By: Claude …` / `noreply@anthropic.com` ones, to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg`. Run it once per clone before your first commit. Maintainers run
`make check-no-cursor-coauthor` again **after** the release commit and **before** `git push`. If CI fails because
trailers already reached the remote, see [GITHUB_CI.md](GITHUB_CI.md) and run `make strip-cursor-coauthor-from-history`
before `git push --force-with-lease` (maintainer decision).

## Cursor Agent

Contributors using Cursor Agent on this repository should enable these user-level settings (REQ-IDE-006):

1. **Submit with Ctrl + Enter** (macOS: **⌘ + Enter**) — Cursor Settings → Agents / Chat. Enter inserts a newline; Ctrl/⌘+Enter submits.
2. **Queue follow-ups** in user `settings.json`:

```json
{
  "cursor.composer.queueMessageDefaultBehavior": "queue"
}
```

Do not use `"steer"` as the default: it interrupts a running agent turn.
