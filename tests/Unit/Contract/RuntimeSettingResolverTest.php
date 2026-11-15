<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Unit\Contract;

use DateTimeImmutable;
use Nowo\RuntimeSettingContract\Bridge\Symfony\Cache\Psr6RuntimeValueCache;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingUnavailable;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingErrorPolicy;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriteContext;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\InMemory\InMemoryRuntimeSettingStore;
use Nowo\RuntimeSettingContract\Tests\Fixtures\DemoLimitValidator;
use Nowo\RuntimeSettingContract\Tests\Support\CountingSource;
use Nowo\RuntimeSettingContract\Tests\Support\MutableClock;
use Nowo\RuntimeSettingContract\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function count;
use function str_repeat;

final class RuntimeSettingResolverTest extends TestCase
{
    private MutableClock $clock;

    private InMemoryRuntimeSettingStore $store;

    private CountingSource $source;

    private RecordingLogger $logger;

    private RuntimeSettingWriteContext $context;

    protected function setUp(): void
    {
        $this->clock   = new MutableClock();
        $this->store   = new InMemoryRuntimeSettingStore($this->clock);
        $this->source  = new CountingSource($this->store);
        $this->logger  = new RecordingLogger();
        $this->context = new RuntimeSettingWriteContext('cli:test', new RuntimeValueFingerprinter(str_repeat('k', 32)));
    }

    // --- Source selection (spec §7) -------------------------------------------------------------------------------

    #[Test]
    public function envSourceNeverReadsTheStore(): void
    {
        $definition = $this->int(RuntimeSettingSource::Env, configured: 30);
        $this->store->set($definition, '45', $this->context);

        self::assertSame(30, $this->resolver()->get($definition));
        self::assertSame(0, $this->source->versionReads + $this->source->valueReads);
    }

    #[Test]
    public function envSourceUsesTheDefaultWhenNothingIsConfigured(): void
    {
        self::assertSame(7, $this->resolver()->get($this->int(RuntimeSettingSource::Env, default: 7)));
        self::assertNull($this->resolver()->get($this->int(RuntimeSettingSource::Env)));
        self::assertSame([], $this->resolver()->candidates($this->int(RuntimeSettingSource::Env)));
    }

    #[Test]
    public function dbSourceReadsTheStoreAndUsesTheDefaultWhenUnset(): void
    {
        $definition = $this->int(RuntimeSettingSource::Db, configured: 30, default: 90);
        $resolver   = $this->resolver();

        self::assertSame(90, $resolver->get($definition), 'db ignores the configured value');

        $this->store->set($definition, '45', $this->context);
        $resolver->clearRuntimeCache();
        self::assertSame(45, $resolver->get($definition));
    }

    #[Test]
    public function dbSourceFailsWhenARequiredSettingIsUnset(): void
    {
        $this->expectException(RuntimeSettingUnavailable::class);
        $this->expectExceptionMessage('"chat.days" is required but has no value');

        $this->resolver()->get($this->int(RuntimeSettingSource::Db, configured: 30, required: true));
    }

    #[Test]
    public function dbThenEnvFallsBackToTheConfiguredValue(): void
    {
        $definition = $this->int(RuntimeSettingSource::DbThenEnv, configured: 30, default: 90);
        $resolver   = $this->resolver();

        self::assertSame(30, $resolver->get($definition));

        $this->store->set($definition, '45', $this->context);
        $resolver->clearRuntimeCache();
        self::assertSame(45, $resolver->get($definition));

        $this->store->unset($definition, $this->context);
        $resolver->clearRuntimeCache();
        self::assertSame(30, $resolver->get($definition));
    }

    #[Test]
    public function dbThenEnvUsesTheDefaultWhenNothingIsConfigured(): void
    {
        self::assertSame(90, $this->resolver()->get($this->int(RuntimeSettingSource::DbThenEnv, default: 90)));
    }

    // --- Failure handling ---------------------------------------------------------------------------------------

    #[Test]
    public function failClosedThrowsWhenTheSourceFails(): void
    {
        $this->source->failWith = new RuntimeException('SQLSTATE[HY000] password=REDACTED-TEST');

        try {
            $this->resolver()->get($this->int(RuntimeSettingSource::DbThenEnv, configured: 30));
            self::fail('Expected RuntimeSettingUnavailable.');
        } catch (RuntimeSettingUnavailable $e) {
            self::assertStringNotContainsString('REDACTED', $e->getMessage());
            self::assertSame($this->source->failWith, $e->getPrevious());
        }
    }

    #[Test]
    public function fallbackWarnsAndUsesTheConfiguredValueWhenTheSourceFails(): void
    {
        $this->source->failWith = new RuntimeException('down');

        self::assertSame(30, $this->resolver()->get($this->int(RuntimeSettingSource::Db, configured: 30, onError: RuntimeSettingErrorPolicy::Fallback)));
        self::assertSame('warning', $this->logger->records[0]['level']);
        self::assertSame('chat.days', $this->logger->records[0]['context']['id']);
    }

    #[Test]
    public function anInvalidVersionTokenIsASourceFailure(): void
    {
        $this->source->forcedVersion = '';

        $this->expectException(RuntimeSettingUnavailable::class);

        $this->resolver()->get($this->int(RuntimeSettingSource::Db));
    }

    #[Test]
    public function anOversizedVersionTokenIsASourceFailure(): void
    {
        $this->source->forcedVersion = str_repeat('v', 129);

        $this->expectException(RuntimeSettingUnavailable::class);

        $this->resolver()->get($this->int(RuntimeSettingSource::Db));
    }

    // --- Validation on read ------------------------------------------------------------------------------------

    #[Test]
    public function theValidatorRefusesAnInvalidStoredValueFailClosed(): void
    {
        $definition = $this->int(RuntimeSettingSource::Db, validator: new DemoLimitValidator());
        $this->store->set($definition, '0', $this->context);

        $this->expectException(InvalidRuntimeSettingValue::class);
        $this->expectExceptionMessage('Invalid value for runtime setting "chat.days": must be at least 1');

        $this->resolver()->get($definition);
    }

    #[Test]
    public function theValidatorRefusesAnInvalidStoredValueWithFallback(): void
    {
        $definition = $this->int(RuntimeSettingSource::Db, configured: 30, validator: new DemoLimitValidator(), onError: RuntimeSettingErrorPolicy::Fallback);
        $this->store->set($definition, 'not-a-number', $this->context);

        self::assertSame(30, $this->resolver()->get($definition));
        self::assertStringNotContainsString('not-a-number', $this->logger->text());
    }

    #[Test]
    public function theValidatorClampsStoredValues(): void
    {
        $definition = $this->int(RuntimeSettingSource::Db, validator: new DemoLimitValidator());
        $this->store->set($definition, '99999999', $this->context);

        self::assertSame(100, $this->resolver()->get($definition));
    }

    #[Test]
    public function theValidatorAlsoRunsOnTheConfiguredValue(): void
    {
        $this->expectException(InvalidRuntimeSettingValue::class);

        $this->resolver()->get($this->int(RuntimeSettingSource::Env, configured: 0, validator: new DemoLimitValidator()));
    }

    // --- Memo, version TTL and cache ------------------------------------------------------------------------------

    #[Test]
    public function oneValuePerRequest(): void
    {
        $definition = $this->int(RuntimeSettingSource::Db);
        $resolver   = $this->resolver();
        $this->store->set($definition, '1', $this->context);

        self::assertSame(1, $resolver->get($definition));
        $this->store->set($definition, '2', $this->context);
        self::assertSame(1, $resolver->get($definition), 'same request, same value');
        self::assertSame(1, $this->source->versionReads);
        self::assertSame(1, $this->source->valueReads);

        $resolver->clearRuntimeCache();
        self::assertSame(2, $resolver->get($definition), 'next request (version TTL 0) sees the change');
    }

    #[Test]
    public function theVersionIsReadOncePerRequestPerNamespace(): void
    {
        $a        = $this->int(RuntimeSettingSource::Db);
        $b        = new RuntimeSettingDefinition('chat.other', RuntimeSettingType::Int, RuntimeSettingSource::Db);
        $resolver = $this->resolver();

        $resolver->get($a);
        $resolver->get($b);

        self::assertSame(1, $this->source->versionReads);
        self::assertSame(2, $this->source->valueReads);
    }

    #[Test]
    public function aChangeIsSeenWithinTheVersionTtl(): void
    {
        $definition = $this->int(RuntimeSettingSource::Db);
        $resolver   = $this->resolver(versionTtl: 5, cache: new Psr6RuntimeValueCache(new ArrayAdapter()));
        $this->store->set($definition, '1', $this->context);
        self::assertSame(1, $resolver->get($definition));

        // Another process writes.
        $this->store->set($definition, '2', $this->context);

        $this->clock->advance('+4 seconds');
        $resolver->clearRuntimeCache();
        self::assertSame(1, $resolver->get($definition), 'version token still fresh: the cached value is used');

        $this->clock->advance('+1 second');
        $resolver->clearRuntimeCache();
        self::assertSame(2, $resolver->get($definition), 'after version_ttl the new token is read');
        self::assertSame(2, $this->source->versionReads);
    }

    #[Test]
    public function aClockGoingBackwardsRereadsTheVersion(): void
    {
        $definition = $this->int(RuntimeSettingSource::Db);
        $resolver   = $this->resolver(versionTtl: 5);
        $resolver->get($definition);

        $this->clock->advance('-1 second');
        $resolver->clearRuntimeCache();
        $resolver->get($definition);

        self::assertSame(2, $this->source->versionReads);
    }

    #[Test]
    public function theSharedCacheIsKeyedByVersion(): void
    {
        $definition = $this->int(RuntimeSettingSource::Db);
        $cache      = new Psr6RuntimeValueCache(new ArrayAdapter());
        $this->store->set($definition, '1', $this->context);

        $first = $this->resolver(cache: $cache);
        self::assertSame(1, $first->get($definition));

        // A second worker with the same pool: version read, value from the cache.
        $second = $this->resolver(cache: $cache);
        self::assertSame(1, $second->get($definition));
        self::assertSame(1, $this->source->valueReads);

        // A write changes the token: the cached entry is unreachable.
        $this->store->set($definition, '2', $this->context);
        $second->clearRuntimeCache();
        self::assertSame(2, $second->get($definition));
        self::assertSame(2, $this->source->valueReads);
    }

    #[Test]
    public function theSharedCacheRemembersAnUnsetSetting(): void
    {
        $definition = $this->int(RuntimeSettingSource::DbThenEnv, configured: 5);
        $cache      = new Psr6RuntimeValueCache(new ArrayAdapter());

        self::assertSame(5, $this->resolver(cache: $cache)->get($definition));
        self::assertSame(5, $this->resolver(cache: $cache)->get($definition));
        self::assertSame(1, $this->source->valueReads);
    }

    #[Test]
    public function secretsNeverReachTheSharedCache(): void
    {
        $pool       = new ArrayAdapter();
        $definition = new RuntimeSettingDefinition('API_TOKEN', RuntimeSettingType::String, RuntimeSettingSource::Db, secret: true);
        $this->store->set($definition, 'REDACTED-TEST-SECRET', $this->context);

        self::assertSame('REDACTED-TEST-SECRET', $this->resolver(cache: new Psr6RuntimeValueCache($pool))->get($definition));
        self::assertSame([], $pool->getValues());
    }

    #[Test]
    public function forgetNamespaceDropsOnlyThatNamespace(): void
    {
        $chat     = $this->int(RuntimeSettingSource::Db);
        $mail     = new RuntimeSettingDefinition('mail.port', RuntimeSettingType::Int, RuntimeSettingSource::Db);
        $env      = new RuntimeSettingDefinition('MAIL_HOST', RuntimeSettingType::String, RuntimeSettingSource::Db);
        $resolver = $this->resolver(versionTtl: 300);
        $this->store->set($chat, '1', $this->context);
        $this->store->set($mail, '25', $this->context);
        $this->store->set($env, 'a', $this->context);
        $resolver->get($chat);
        $resolver->get($mail);
        $resolver->get($env);

        $this->store->set($chat, '2', $this->context);
        $this->store->set($mail, '26', $this->context);
        $resolver->forgetNamespace('chat');

        self::assertSame(2, $resolver->get($chat));
        self::assertSame(25, $resolver->get($mail), 'other namespaces keep their memo');
    }

    #[Test]
    public function resetDropsTheVersionTokensToo(): void
    {
        $definition = $this->int(RuntimeSettingSource::Db);
        $resolver   = $this->resolver(versionTtl: 300, cache: new Psr6RuntimeValueCache(new ArrayAdapter()));
        $this->store->set($definition, '1', $this->context);
        $resolver->get($definition);
        $this->store->set($definition, '2', $this->context);

        $resolver->clearRuntimeCache();
        self::assertSame(1, $resolver->get($definition), 'TTL 300: the boundary keeps the token, the cached value is used');

        $resolver->reset();
        self::assertSame(2, $resolver->get($definition));
    }

    #[Test]
    public function stateStaysBounded(): void
    {
        $resolver = $this->resolver(versionTtl: 300);
        for ($i = 0; $i < RuntimeSettingResolver::MAX_ENTRIES + 5; ++$i) {
            $resolver->get(new RuntimeSettingDefinition('ns' . $i . '.k', RuntimeSettingType::Int, RuntimeSettingSource::Db));
        }

        $memo     = (fn (): array => $this->memo)->call($resolver);
        $versions = (fn (): array => $this->versions)->call($resolver);
        $request  = (fn (): array => $this->requestVersions)->call($resolver);
        self::assertLessThanOrEqual(RuntimeSettingResolver::MAX_ENTRIES, count($memo));
        self::assertLessThanOrEqual(RuntimeSettingResolver::MAX_ENTRIES, count($versions));
        self::assertLessThanOrEqual(RuntimeSettingResolver::MAX_ENTRIES, count($request));
    }

    #[Test]
    public function refusesAnOutOfRangeVersionTtl(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);

        new RuntimeSettingResolver($this->source, versionTtl: 301);
    }

    #[Test]
    public function worksWithDefaultCollaborators(): void
    {
        $resolver = new RuntimeSettingResolver($this->source);

        self::assertNull($resolver->get($this->int(RuntimeSettingSource::Db)));
    }

    // --- Rotation candidates ---------------------------------------------------------------------------------------

    #[Test]
    public function bothCurrentAndPreviousAreAcceptedInTheWindowOnlyCurrentAfter(): void
    {
        $definition = new RuntimeSettingDefinition('webhook.secret', RuntimeSettingType::String, RuntimeSettingSource::Db, secret: true);
        $resolver   = $this->resolver();
        $this->store->set($definition, 'REDACTED-TEST-OLD', $this->context);
        $this->store->rotate($definition, 'REDACTED-TEST-NEW', $this->clock->now()->modify('+1 hour'), $this->context);

        self::assertSame('REDACTED-TEST-NEW', $resolver->get($definition));
        self::assertSame(['REDACTED-TEST-NEW', 'REDACTED-TEST-OLD'], $resolver->candidates($definition));

        $this->clock->advance('+1 hour');
        $resolver->clearRuntimeCache();
        self::assertSame(['REDACTED-TEST-NEW'], $resolver->candidates($definition));
    }

    #[Test]
    public function anInvalidPreviousValueIsDropped(): void
    {
        $definition = $this->int(RuntimeSettingSource::Db, validator: new DemoLimitValidator());
        $this->store->set($definition, '0', $this->context);
        $this->store->rotate($definition, '5', new DateTimeImmutable('2030-01-01'), $this->context);

        self::assertSame([5], $this->resolver()->candidates($definition));
        self::assertSame('warning', $this->logger->records[0]['level']);
    }

    private function resolver(int $versionTtl = 0, ?Psr6RuntimeValueCache $cache = null): RuntimeSettingResolver
    {
        return new RuntimeSettingResolver($this->source, $cache, $this->clock, $versionTtl, $this->logger);
    }

    private function int(
        RuntimeSettingSource $source,
        mixed $configured = null,
        mixed $default = null,
        bool $required = false,
        ?DemoLimitValidator $validator = null,
        RuntimeSettingErrorPolicy $onError = RuntimeSettingErrorPolicy::FailClosed,
    ): RuntimeSettingDefinition {
        return new RuntimeSettingDefinition('chat.days', RuntimeSettingType::Int, $source, $onError, validator: $validator, configured: $configured, default: $default, required: $required);
    }
}
