# Configuration

## Table of contents

- [Bridge configuration](#bridge-configuration)
- [Per-setting configuration (adopting bundles)](#per-setting-configuration-adopting-bundles)
  - [source](#source)
  - [key](#key)
  - [on_error](#on_error)
- [What cannot move to the database](#what-cannot-move-to-the-database)
- [Bundle authors: offering runtime settings](#bundle-authors-offering-runtime-settings)

## Bridge configuration

```yaml
# config/packages/nowo_runtime_setting.yaml
nowo_runtime_setting:
    source: ~            # service id of a RuntimeValueSourceInterface (null: keep an existing alias)
    writer: ~            # service id of a RuntimeSettingWriterInterface (null: keep an existing alias)
    cache_pool: ~        # PSR-6 pool id (e.g. cache.app) for NON-secret values; recommended in production
    cache_lifetime: 3600 # seconds a cached value is kept (1..86400); a version change makes it unreachable earlier
    version_ttl: 5       # seconds a namespace version token is reused across requests (0..300; 0 = read every request)
    fingerprint_key: ~   # secret (>= 16 bytes) the fingerprint HMAC key is derived from; null = kernel.secret
    command:
        enabled: false   # register nowo:runtime-setting:get|set|unset|list|rotate
```

| Key | Default | Effect |
|---|---|---|
| `source` / `writer` | `null` | Aliases `RuntimeValueSourceInterface` / `RuntimeSettingWriterInterface`. An adapter bundle or the host may alias the interfaces directly instead; only when nothing does, the "unconfigured" store is bound (reads fail, writes are refused). |
| `cache_pool` | `null` | Shares non-secret values between workers and hosts, keyed by version token. Secrets never go there. Without a pool every request reads each setting it uses from the source, one query per setting and request (the version token only gates the pool). **Recommended in production.** |
| `version_ttl` | `5` | Upper bound for a change to reach a running worker when a pool is used. `0` reads the namespace version on every request (one cheap query per namespace). |
| `fingerprint_key` | `kernel.secret` | The HMAC key for fingerprints of **non-secret** values is derived from it with the label `nowo.runtime-setting.fingerprint.v1` (domain separation). Rotating it changes every fingerprint; a dedicated `fingerprint_key` avoids that. Secrets are never fingerprinted. |
| `command.enabled` | `false` | Turn on deliberately. When off, the commands are not registered at all. |

## Per-setting configuration (adopting bundles)

Each adopting bundle exposes a `runtime:` node for its settings. Example for ChatKit:

```yaml
nowo_chat_kit:
    retention_days: '%env(int:CHAT_RETENTION_DAYS)%'   # configured value: the env fallback
    runtime:
        retention_days:
            source: db_then_env          # env (default) | db | db_then_env
            key: CHAT_RETENTION_DAYS     # an env variable name, or namespace.key (chat.retention_days)
            on_error: fail_closed        # fallback | fail_closed
```

### source

| Value | Behaviour |
|---|---|
| `env` | Today's behaviour, the default. Nothing reads the source; the commands refuse to write the setting. |
| `db` | The source only. Unset: the bundle's documented default, or a failure when the setting is required. The configured value is ignored. |
| `db_then_env` | The source first; unset falls back to the configured (env) value, then to the default. |

### key

Either `namespace.key` (lowercase, `[a-z][a-z0-9_]*` then dot-separated `[a-z0-9_]+` segments, at most 193 bytes) or
an environment variable name (`[A-Z][A-Z0-9_]*`, at most 128 bytes, the RuntimeEnvBundle rule), always written bare:
`env.NAME` is refused (one spelling per id). Env-named keys share the reserved namespace `env`, so one version token
covers them all. The id is matched byte for byte: no case folding,
no trimming.

### on_error

Applies when the source cannot be read (database down, malformed row, invalid version token) and when the stored
value fails the type or the bundle's validator.

| Value | Behaviour |
|---|---|
| `fallback` | Log a warning (the id only, never the value) and use the configured value (then the default). |
| `fail_closed` | Throw `RuntimeSettingUnavailable` / `InvalidRuntimeSettingValue`: the operation aborts. The default. Required for settings that delete data or affect security. |

A configured value the validator refuses always throws: it is a configuration error, not a runtime failure.

## What cannot move to the database

- Needed before the database exists: `DATABASE_URL` and anything used to reach the database.
- Kernel or container identity: `APP_SECRET` / `kernel.secret`, `APP_ENV`.
- Values resolved at container compile time: routes and prefixes, service or feature registration, table names and
  prefixes, bundle enable flags, Messenger transport DSNs.
- Read by infrastructure outside PHP: Caddy / FrankenPHP, Docker.

## Bundle authors: offering runtime settings

```php
use Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection\RuntimeSettingsConfiguration;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;

// In the bundle's configuration tree:
RuntimeSettingsConfiguration::appendTo($rootNode, [
    'retention_days' => ['key' => 'chat.retention_days', 'on_error' => 'fail_closed'],
]);

// In load() / loadExtension():
RuntimeSettingsConfiguration::registerDefinition(
    $container,
    'nowo_chat_kit.runtime_setting.retention_days',
    $config['runtime']['retention_days'],
    RuntimeSettingType::Int,
    $config['retention_days'],                     // configured (env) value
    validatorServiceId: ChatRetentionValidator::class,
    default: 90,
);
```

A setting the host can switch to `db` or `db_then_env` needs a validator: `registerDefinition()` throws
`RuntimeSettingConfigurationError` at container build when the host picks a writable source and the bundle passes no
`validatorServiceId`, and `RuntimeSettingDefinition` refuses the same at construction. Use
`Contract\Validator\IntRange` (both sides bounded, refuse or clamp), `Contract\Validator\SingleLineString` (no control
characters, bounded length; for anything reaching a header, DSN, URL or log line), your own validator, or
`Contract\Validator\Unbounded` as an explicit, reviewable opt-out. Bound numbers on both sides.

The definition service is tagged `nowo_runtime_setting.definition` and collected by `RuntimeSettingRegistry`. The
bundle's domain keeps its own typed port (for example `RetentionPolicyProviderInterface::retentionDays(): ?int`); an
application-layer adapter implements it with `RuntimeSettingResolver::get()`.
