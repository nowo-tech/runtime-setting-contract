# FrankenPHP worker audit (`resetKernel=false`)

REQ-RUNTIME-002: the package must work under FrankenPHP worker mode with the kernel kept alive and **not** reset
between requests (no services resetter).

## Table of contents

- [State inventory](#state-inventory)
- [Boundaries](#boundaries)
- [Proof](#proof)
- [Igor](#igor)

## State inventory

| Class | State | Lifetime | Cleared by |
|---|---|---|---|
| `RuntimeSettingResolver` | `memo` (values per id) | one request | `clearRuntimeCache()` at every boundary, `forgetNamespace()` after a write, `reset()` |
| `RuntimeSettingResolver` | `requestVersions` (token per namespace) | one request | same |
| `RuntimeSettingResolver` | `versions` (token + read time per namespace) | at most `version_ttl` seconds, by design | `forgetNamespace()`, `reset()` |
| `InMemoryRuntimeSettingStore` | values, versions, audit trail | object lifetime (it is a store) | never; the audit trail keeps the last 1,000 entries |
| All other services | none (readonly) | — | — |

Every map is bounded (`RuntimeSettingResolver::MAX_ENTRIES` = 1,024 entries). No statics, no `$_ENV` / `putenv()`
mutation, no `exit`, no sleeping, no locks or handles kept across calls (the commands close the STDIN handle they
open).

Secrets are only ever held in the per-request memo; they never reach the shared PSR-6 pool.

## Boundaries

`RuntimeSettingBoundaryListener` clears the per-request memo on:

- main `kernel.request` (priority 4096; sub-requests keep the main request's values) and `kernel.terminate`;
- `console.command` and `console.terminate`;
- Messenger `WorkerMessageReceivedEvent`, `WorkerMessageHandledEvent` and `WorkerMessageFailedEvent`.

It also implements `ResetInterface` (`kernel.reset`), and the resolver itself is tagged `kernel.reset`; neither is
relied on alone.

## Proof

- `tests/Integration/Symfony/WorkerModeTest.php`: one kernel with the services resetter replaced by a no-op, 2,000
  requests alternating two values written by another kernel; every request serves the current value, memory growth
  after warm-up stays under 1 MiB.
- `tests/Integration/Symfony/LiveChangeAcrossProcessesTest.php`: a second PHP process changes the value through the
  console command; the kept-alive kernel serves it within `version_ttl`, with the same container instance.
- `tests/Unit/Contract/RuntimeSettingResolverTest.php::stateStaysBounded`.

## Igor

`composer igor` exits 0. Mutations that are the documented per-request memo, the TTL-bounded version map and the
reference store carry `// @igor-ignore` with the reason. Igor still prints one WARN for
`RuntimeSettingBoundaryListener` ("property resolver is mutated but not reset in reset()"): the listener has no state
of its own; its `reset()` delegates to `RuntimeSettingResolver::reset()`.
