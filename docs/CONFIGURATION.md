# Configuration

## Table of contents

- [Bridge configuration](#bridge-configuration)
- [Per-setting configuration (adopting bundles)](#per-setting-configuration-adopting-bundles)
  - [source](#source)
  - [key](#key)
  - [on_error](#on_error)
  - [provider](#provider)
- [Read-only value providers (1.1)](#read-only-value-providers-11)
  - [Writing a provider](#writing-a-provider)
  - [Registering providers](#registering-providers)
  - [Binding settings to a provider](#binding-settings-to-a-provider)
  - [Caching](#caching)
  - [Failures and security](#failures-and-security)
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
    providers: {}        # 1.1: read-only value providers, <name>: { service: <id>, cache_ttl: 0..3600 }
    setting_providers: {} # 1.1: <setting id>: <provider name>, for any setting a bundle registered
    command:
        enabled: false   # register nowo:runtime-setting:get|set|unset|list|rotate
```

| Key | Default | Effect |
|---|---|---|
| `source` / `writer` | `null` | Aliases `RuntimeValueSourceInterface` / `RuntimeSettingWriterInterface`. An adapter bundle or the host may alias the interfaces directly instead; only when nothing does, the "unconfigured" store is bound (reads fail, writes are refused). |
| `cache_pool` | `null` | Shares non-secret values between workers and hosts, keyed by version token. Secrets never go there. Without a pool every request reads each setting it uses from the source, one query per setting and request (the version token only gates the pool). **Recommended in production.** |
| `version_ttl` | `5` | Upper bound for a change to reach a running worker when a pool is used. `0` reads the namespace version on every request (one cheap query per namespace). |
| `fingerprint_key` | `kernel.secret` | The HMAC key for fingerprints of **non-secret** values is derived from it with the label `nowo.runtime-setting.fingerprint.v1` (domain separation). Rotating it changes every fingerprint; a dedicated `fingerprint_key` avoids that. Secrets are never fingerprinted. |
| `providers` | `{}` | 1.1. Named `RuntimeValueProviderInterface` services a setting can read from (see [Read-only value providers](#read-only-value-providers-11)). Adapter bundles add theirs with the `nowo_runtime_setting.provider` tag. |
| `setting_providers` | `{}` | 1.1. Binds a registered setting (`source: db` / `db_then_env`) to a provider by id, without the owning bundle offering a `provider` option. Checked when the container is built. |
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

### provider

1.1, optional. The name of a registered read-only provider to read the value from instead of the default source
(`source: db` or `db_then_env` only; with `source: env` the build fails). Absent (the default): the default source,
exactly as in 1.0. A bundle that does not offer the option yet can still be bound by the host with
`nowo_runtime_setting.setting_providers`.

```yaml
nowo_chat_kit:
    runtime:
        retention_days: { source: db, key: chat.retention_days, provider: tenant_settings }
```

## Read-only value providers (1.1)

The default source (RuntimeEnvBundle's table, InstanceSettings) holds values the commands and the admin UI write. A
**provider** answers "which query fetches this setting" when the value lives elsewhere: a per-tenant table, a join over
host tables, another connection. A setting chooses one with `provider: <name>`; everything else is unchanged (type,
the bundle's validator, `on_error`, `required`, secrets, rotation candidates).

Providers are **read-only**: `nowo:runtime-setting:set|unset|rotate`, `RuntimeSettingUpdater` and the adapters' admin
UIs refuse the setting with `RuntimeSettingNotWritable`. Change the value where the provider reads it.

### Writing a provider

Implement `Nowo\RuntimeSettingContract\Contract\RuntimeValueProviderInterface` (framework-free) for complex cases:

```php
final readonly class TenantSettingsProvider implements RuntimeValueProviderInterface
{
    public function __construct(private Connection $connection, private TenantContext $tenant) {}

    public function read(RuntimeSettingId $id): ?StoredRuntimeValue
    {
        $value = $this->connection->fetchOne(
            'SELECT value FROM tenant_setting WHERE tenant_id = :tenant AND name = :name',
            ['tenant' => $this->tenant->id(), 'name' => $id->toString()],
        );

        return $value === false || $value === null ? null : new StoredRuntimeValue((string) $value);
    }
}
```

Rules: parameterised SQL only; at most one row; committed data read on every call; no per-request state in the
service (it serves every request of a worker; read request-scoped data such as the tenant from a service that is reset
at the boundary); never put a value or SQL in an exception message. Return `new StoredRuntimeValue($current, $previous,
$expiresAt)` to give verifiers the previous value during a rotation window. For a plain query configured in YAML, use
nowo-tech/runtime-env-bundle's DBAL query provider (1.10).

> **Caching and request-scoped providers.** The shared cache is keyed by setting id only. A provider whose answer
> depends on the request (tenant, user) **must** use `cache_ttl: 0`, or one tenant's value is served to another.

### Registering providers

```yaml
nowo_runtime_setting:
    cache_pool: cache.app
    providers:
        tenant_settings: { service: App\Settings\TenantSettingsProvider, cache_ttl: 0 }
        billing_db:      { service: App\Settings\BillingProvider, cache_ttl: 60 }
```

Names are lowercase snake case (`[a-z][a-z0-9_]{0,63}`). Adapter bundles tag their services instead:
`nowo_runtime_setting.provider` with `name` and an optional `cache_ttl`. Two providers with one name fail the build.

DDD / framework-free hosts pass the registry to the resolver:

```php
$resolver = new RuntimeSettingResolver($source, $cache, $clock, 5, $logger, new RuntimeValueProviderRegistry([
    new RuntimeValueProviderBinding('tenant_settings', $provider, cacheTtl: 0),
]));
```

### Binding settings to a provider

Either the bundle's own `runtime.<setting>.provider` option, or, for any registered setting:

```yaml
nowo_runtime_setting:
    setting_providers:
        chat.retention_days: billing_db
        CHAT_WEBHOOK_SECRET: billing_db
```

The build fails when an id is not registered by any bundle, when the setting uses `source: env`, when the bundle option
and the map name different providers, or when a provider is not registered.

### Caching

| Layer | Provider settings |
|---|---|
| Per-request memo | Always: one read per setting and request, cleared at every boundary. |
| Shared cache (`cache_pool`) | Non-secret values only, and only when the provider's `cache_ttl` > 0. Entries live for one time bucket of `cache_ttl` seconds (the same bucket in every worker): a change at the provider reaches every worker within `cache_ttl`. Changing the name or the TTL changes the token. |
| Secrets | Never in the shared cache, whatever `cache_ttl` says: read once per request. |

Providers have no namespace version token: `version_ttl` does not apply to them.

### Failures and security

- Any exception a provider throws becomes `RuntimeSettingSourceFailure` ("Runtime value provider "x" could not be read
  (ExceptionClass)") **without** the original message or chain, which may quote SQL or a value; then `on_error`
  applies (`fail_closed` by default). Under `fallback` only the id and the exception class are logged.
- Values longer than 65536 bytes are refused before parsing; the setting's own `maxLength`, type and validator apply
  to every provided value, exactly as for the default source.
- A setting naming a provider that is not registered is a `RuntimeSettingConfigurationError`, even under `fallback`
  (a wiring error is not a "database down").
- Give providers a database user that can only `SELECT` the tables they need.

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
