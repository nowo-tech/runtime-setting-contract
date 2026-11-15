# Usage

## Table of contents

- [Reading a setting](#reading-a-setting)
- [Rotation: accepting current and previous](#rotation-accepting-current-and-previous)
- [Changing a setting from the console](#changing-a-setting-from-the-console)
- [Changing a setting from an admin UI](#changing-a-setting-from-an-admin-ui)
- [Reacting to a change](#reacting-to-a-change)
- [DDD host without Symfony](#ddd-host-without-symfony)
- [Writing a source and writer](#writing-a-source-and-writer)
- [Tests every adopter must have](#tests-every-adopter-must-have)

## Reading a setting

The bundle's domain keeps its own typed port; an application-layer adapter reads through the resolver:

```php
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;

final readonly class RuntimeRetentionPolicyProvider implements RetentionPolicyProviderInterface
{
    public function __construct(
        private RuntimeSettingResolver $resolver,
        private RuntimeSettingDefinition $retentionDays, // the bundle's registered definition
    ) {
    }

    public function retentionDays(): ?int
    {
        $days = $this->resolver->get($this->retentionDays);

        return \is_int($days) ? $days : null;
    }
}
```

`get()` returns the validated value: stored, configured (env) or default, depending on `source`; `null` when none
applies. Within one request it always returns the same value.

## Rotation: accepting current and previous

```php
foreach ($this->resolver->candidates($this->webhookSecret) as $secret) {
    if (hash_equals(hash_hmac('sha256', $payload, (string) $secret), $signature)) {
        return true;
    }
}

return false;
```

`candidates()` returns the current value first, then the previous one while its rotation window is open. Use it
wherever a secret is **verified** (tokens, webhook signatures, credentials of incoming calls). Code that **sends** a
secret uses `get()`.

## Changing a setting from the console

Enable the commands (`nowo_runtime_setting.command.enabled: true`), then:

```bash
bin/console nowo:runtime-setting:list [--namespace=chat]
bin/console nowo:runtime-setting:get chat.retention_days            # secrets masked, no fingerprint
bin/console nowo:runtime-setting:set chat.retention_days 45 [--dry-run]
printf '%s' "$NEW_TOKEN" | bin/console nowo:runtime-setting:set API_TOKEN --stdin
bin/console nowo:runtime-setting:unset chat.retention_days         # back to the configured (env) value
printf '%s' "$NEW_TOKEN" | bin/console nowo:runtime-setting:rotate API_TOKEN --stdin --keep-previous=PT1H
bin/console nowo:runtime-setting:purge                              # expired previous values, at rest
```

Rules (all enforced by `RuntimeSettingUpdater`, shared with any admin UI):

- Only registered settings; unknown ids are refused (exit code 1).
- `source: env` settings are refused: an environment value cannot change at runtime.
- The value is bounded, parsed by type and run through the bundle's validator **before** anything is written. An
  invalid value is refused with exit code 1 and nothing is written. A clamped value is stored clamped.
- Secrets are never accepted as an argument (shell history, `ps`): `--stdin` is mandatory and nothing echoes them. One
  trailing newline is dropped. Prefer `printf '%s'` over an interactive terminal.
- Every change is audited by the writer with the actor `cli:<os user>`, the id and the old and new fingerprints, never
  the value. For a **secret** no fingerprint exists anywhere (audit entries, command output, dry runs, the updater's
  result): only the fact that it changed is recorded, because a fingerprint lets anyone allowed to write the secret
  confirm guesses. `SettingChangedEvent` (ids only) is dispatched after commit.
- `--dry-run` validates and prints what would change.
- `rotate --keep-previous` takes an ISO-8601 duration from `PT0S` to `P30D` (default `PT1H`). A previous value is
  never accepted after its expiry. It is deleted at rest by the next write in its namespace, and by
  `nowo:runtime-setting:purge` (`RuntimeSettingUpdater::purgeExpired()`): schedule it (cron, Scheduler, a message)
  at least as often as your shortest rotation window.
- STDIN is bounded: input longer than the limit is refused, never cut.

Running workers pick the change up within `version_ttl` (with a shared pool) or on their next request (without one),
with no restart.

## Changing a setting from an admin UI

Use the same updater, with the signed-in user as actor:

```php
$updater->prepare('chat.retention_days', $submitted);          // form validation: throws InvalidRuntimeSettingValue
$updater->set('chat.retention_days', $submitted, 'user:' . $user->getId());
```

`prepare()` runs the same type, bound and validator checks without writing. Catch
`RuntimeSettingExceptionInterface` and show its message: it never contains the value.

## Reacting to a change

```php
#[AsEventListener]
public function onSettingChanged(SettingChangedEvent $event): void
{
    if ($event->namespace === 'chat' && $event->key === 'retention_days') {
        // ids only: read the new value through the resolver if needed
    }
}
```

The event is dispatched in the process that made the change, after the writer committed. Other processes learn about
the change through the version token, not through the event.

## DDD host without Symfony

```php
$resolver = new RuntimeSettingResolver($mySource, cache: null, clock: $clock, versionTtl: 0, logger: $logger);
$updater  = new RuntimeSettingUpdater(
    new RuntimeSettingRegistry($definitions),
    $myWriter,
    $resolver,
    new RuntimeValueFingerprinter($secretKey),
    $psr14Dispatcher,
);

// At every request / message boundary of a long-running process:
$resolver->clearRuntimeCache();
```

Nothing in `src/Contract` imports Symfony or Doctrine; CI runs it with `composer install --no-dev`.

A long-running loop that is not an HTTP request, a console command or a Messenger message (a daemon command with
`while (true)`, a ReactPHP / Swoole loop) must call `clearRuntimeCache()` once per iteration: the resolver keeps one
value per unit of work, and nothing else ends that unit. The memo holds at most 1,024 settings; a unit of work that
reads more is flushed midway and may see two values of one setting.

## Writing a source and writer

Follow the docblocks of `RuntimeValueSourceInterface` and `RuntimeSettingWriterInterface`. The essentials:

- The namespace version token changes with every committed write and is **never reused**, not even after a rollback:
  combine a counter with a random part. Write it in the same transaction as the value.
- One transaction per write, opened and committed by the writer; refuse to run inside a caller's transaction
  (`RuntimeSettingNotWritable::insideTransaction()`).
- `read()` must never return data older than the token `namespaceVersion()` returned just before: read from the
  database on every call (no ORM identity map kept across requests: DBAL / SQL, or refresh the entity), and read the
  token and the value from the same node (no token from the primary and value from a lagging replica). Otherwise a
  stale value is cached under the new token and served to every worker.
- Store namespace and key verbatim; never derive one id form from the other (`chat.retention_days` and
  `CHAT_RETENTION_DAYS` are two settings).
- Encrypt secrets at rest; audit with `RuntimeSettingWriteContext::fingerprint()` (null for secrets: record only that
  the secret changed), never values; do not dispatch events (the updater does).
- Purge expired previous values on every write (at least in the written namespace) and in `purgeExpired()`.
- Compare ids byte for byte (binary / NO PAD collation on MySQL, and `===` on fetched rows).
- `InMemoryRuntimeSettingStore` is a complete reference implementation; `tests/Support/PdoRuntimeSettingStore.php`
  shows the transactional version over PDO.

## Tests every adopter must have

Spec §7, as implemented in this package's own suite (`tests/`):

| Test | Where here |
|---|---|
| Source selection: env / db / db_then_env | `tests/Unit/Contract/RuntimeSettingResolverTest.php` |
| `on_error` fallback and fail_closed when the source throws | same |
| The validator refuses an invalid database value, for read and command | `RuntimeSettingResolverTest`, `CommandsTest` |
| Live change across processes, worker kernel kept alive | `tests/Integration/Symfony/LiveChangeAcrossProcessesTest.php` |
| Rotation: current and previous in the window, only current after | `RuntimeSettingResolverTest`, `RuntimeSettingUpdaterTest` |
| Worker safety: 2,000 requests, flat memory, no cross-request value | `tests/Integration/Symfony/WorkerModeTest.php` |
| DDD host with its own source | `tests/Integration/Ddd/DddHostTest.php` |
