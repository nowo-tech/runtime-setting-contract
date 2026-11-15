# Release

No release yet. 1.0.0 is tagged after the REQ audit and the REQ-SEC-004 security audit.

Maintainers: follow this checklist before creating a tag.

## Table of contents

- [Pre-release checklist](#pre-release-checklist)
- [Tag and publish](#tag-and-publish)

## Pre-release checklist

1. **Update version and docs**
   - Move `[Unreleased]` in [CHANGELOG.md](CHANGELOG.md) to `[x.y.z] - YYYY-MM-DD` and update the compare links.
   - Ensure [UPGRADING.md](UPGRADING.md) covers any behaviour change.
   - Confirm the [SECURITY.md](SECURITY.md) release checklist (12.4.1), including the AI security audit note.
   - Update `extra.branch-alias` in `composer.json` for a new minor.

2. **Run quality checks**

   ```bash
   make release-check
   ```

   This runs: `ensure-up`, `check-no-cursor-coauthor`, `check-open-prs`, `composer-sync`, `cs-fix`, `cs-check`,
   `rector-dry`, `phpstan`, `igor` and `test-coverage` (100% gate).

3. **Open pull requests**: `make check-open-prs` must pass.

4. **CI** green on the exact commit to tag (`gh run list -R nowo-tech/runtime-setting-contract`).

## Tag and publish

5. **Annotated tag**:

   ```bash
   git tag -a v1.0.0 -m "Release v1.0.0: runtime setting contract, resolver, updater and Symfony bridge"
   git push origin v1.0.0
   ```

6. **GitHub release**: [.github/workflows/release.yml](../.github/workflows/release.yml) creates it from the tag message
   and [CHANGELOG.md](CHANGELOG.md); [.github/workflows/sync-releases.yml](../.github/workflows/sync-releases.yml)
   backfills missing ones.

7. **Packagist**: the owner registers `https://github.com/nowo-tech/runtime-setting-contract` once for
   `nowo-tech/runtime-setting-contract`; later tags are picked up automatically.
