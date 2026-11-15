# Runtime Setting Contract Constitution

## Core Principles

### I. Documented integrator contract
Product behavior lives in `specs/001-baseline/spec.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md`, and integrator docs (`USAGE.md`, `CONFIGURATION.md`). The interface docblocks in `src/Contract` are the normative contract for source and writer implementations.

### II. Spec-first, test-proven
PHPUnit and PHPStan are the mechanical proof. Behavioral changes require tests, including the adopter tests of spec §7 (source selection, failure handling, validation, live change, rotation, worker safety, DDD host).

### III. 100% code inventory traceability
Every production file under `src/` must appear in `specs/001-baseline/code-inventory.md`. New files require spec updates in the same PR.

### IV. Framework-free contract
`src/Contract` and `src/InMemory` depend on PHP and PSR interfaces only; Symfony lives in `src/Bridge/Symfony`, optional.

### V. Fail closed, never leak values
Values are bounded and validated on every path; secrets never reach a shared cache, a log, an exception message or command output.

## Governance
Amendments update this file, the baseline spec when principles affect behavior, and `docs/CHANGELOG.md` when consumer-visible.

**Version**: 1.0.0 | **Ratified**: 2026-11-16 | **Last Amended**: 2026-11-16
