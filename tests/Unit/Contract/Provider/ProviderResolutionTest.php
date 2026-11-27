<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Unit\Contract\Provider;

use DateInterval;
use DateTimeImmutable;
use Nowo\RuntimeSettingContract\Bridge\Psr6\Psr6RuntimeValueCache;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingNotWritable;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingSourceFailure;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingUnavailable;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingChange;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingErrorPolicy;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueProviderBinding;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueProviderRegistry;
use Nowo\RuntimeSettingContract\Contract\StoredRuntimeValue;
use Nowo\RuntimeSettingContract\Contract\Validator\IntRange;
use Nowo\RuntimeSettingContract\Contract\Validator\Unbounded;
use Nowo\RuntimeSettingContract\InMemory\InMemoryRuntimeSettingStore;
use Nowo\RuntimeSettingContract\Tests\Support\ArrayValueProvider;
use Nowo\RuntimeSettingContract\Tests\Support\CountingSource;
use Nowo\RuntimeSettingContract\Tests\Support\MutableClock;
use Nowo\RuntimeSettingContract\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function str_repeat;

final class ProviderResolutionTest extends TestCase
{
    private const SQL_IN_MESSAGE = 'SELECT value FROM tenant_settings WHERE key = :key -- REDACTED-TEST-SECRET';

    private MutableClock $clock;

    private CountingSource $source;

    private ArrayValueProvider $provider;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->clock    = new MutableClock('2026-11-27 12:00:00.000000');
        $this->source   = new CountingSource(new InMemoryRuntimeSettingStore($this->clock));
        $this->provider = new ArrayValueProvider();
        $this->logger   = new RecordingLogger();
    }

    // --- Definition ------------------------------------------------------------------------------------------------

    #[Test]
    public function aDefinitionNamesItsProvider(): void
    {
        $definition = $this->int(RuntimeSettingSource::Db);

        self::assertSame('tenant', $definition->provider);
        self::assertTrue($definition->usesProvider());
        self::assertTrue($definition->isReadOnly());
        self::assertTrue($definition->isWritable(), 'unchanged since 1.0: adapters keep routing writes to the updater, which refuses');

        $plain = new RuntimeSettingDefinition('chat.days', RuntimeSettingType::Int, RuntimeSettingSource::Db, validator: new Unbounded());
        self::assertNull($plain->provider);
        self::assertFalse($plain->usesProvider());
        self::assertFalse($plain->isReadOnly());
        self::assertTrue((new RuntimeSettingDefinition('chat.days', RuntimeSettingType::Int))->isReadOnly());
    }

    #[Test]
    public function aDefinitionRefusesAMalformedProviderName(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('names an invalid provider');

        new RuntimeSettingDefinition('chat.days', RuntimeSettingType::Int, RuntimeSettingSource::Db, validator: new Unbounded(), provider: 'Tenant-Settings');
    }

    #[Test]
    public function aDefinitionRefusesAProviderWithSourceEnv(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('uses "source: env"');

        new RuntimeSettingDefinition('chat.days', RuntimeSettingType::Int, RuntimeSettingSource::Env, validator: new Unbounded(), provider: 'tenant');
    }

    #[Test]
    public function aProviderSettingStillNeedsAValidator(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('has no validator');

        new RuntimeSettingDefinition('chat.days', RuntimeSettingType::Int, RuntimeSettingSource::Db, provider: 'tenant');
    }

    // --- Reads -----------------------------------------------------------------------------------------------------

    #[Test]
    public function readsTheProviderAndNeverTheSource(): void
    {
        $this->provider->values['chat.days'] = '45';

        self::assertSame(45, $this->resolver()->get($this->int(RuntimeSettingSource::Db, configured: 30)));
        self::assertSame(1, $this->provider->reads);
        self::assertSame(0, $this->source->versionReads + $this->source->valueReads);
    }

    #[Test]
    public function dbUsesTheDefaultAndDbThenEnvTheConfiguredValueWhenTheProviderHasNone(): void
    {
        $resolver = $this->resolver();

        self::assertSame(90, $resolver->get($this->int(RuntimeSettingSource::Db, configured: 30, default: 90)));
        $resolver->clearRuntimeCache();
        self::assertSame(30, $resolver->get($this->int(RuntimeSettingSource::DbThenEnv, configured: 30, default: 90)));
    }

    #[Test]
    public function aRequiredSettingWithoutValueIsUnavailable(): void
    {
        $this->expectException(RuntimeSettingUnavailable::class);

        $this->resolver()->get(new RuntimeSettingDefinition('chat.days', RuntimeSettingType::Int, RuntimeSettingSource::Db, validator: new Unbounded(), required: true, provider: 'tenant'));
    }

    #[Test]
    public function theBundlesValidatorRunsOnProvidedValues(): void
    {
        $this->provider->values['chat.days'] = '5000';
        $definition                          = new RuntimeSettingDefinition('chat.days', RuntimeSettingType::Int, RuntimeSettingSource::Db, validator: new IntRange(1, 365, false), provider: 'tenant');

        $this->expectException(InvalidRuntimeSettingValue::class);

        $this->resolver()->get($definition);
    }

    #[Test]
    public function anInvalidProvidedValueFallsBackUnderFallback(): void
    {
        $this->provider->values['chat.days'] = 'not-a-number';

        self::assertSame(30, $this->resolver()->get($this->int(RuntimeSettingSource::Db, configured: 30, onError: RuntimeSettingErrorPolicy::Fallback)));
        self::assertStringContainsString('stored value is invalid', $this->logger->text());
    }

    #[Test]
    public function theProvidersRotationWindowGivesTwoCandidates(): void
    {
        $this->provider->values['chat.days'] = new StoredRuntimeValue('2', '1', new DateTimeImmutable('2026-11-27 13:00:00'));
        $resolver                            = $this->resolver();
        $definition                          = $this->int(RuntimeSettingSource::Db);

        self::assertSame([2, 1], $resolver->candidates($definition));

        $this->clock->advance('+2 hours');
        $resolver->clearRuntimeCache();
        self::assertSame([2], $resolver->candidates($definition));
    }

    #[Test]
    public function theProviderIsReadOncePerRequest(): void
    {
        $this->provider->values['chat.days'] = '45';
        $resolver                            = $this->resolver();
        $definition                          = $this->int(RuntimeSettingSource::Db);

        $resolver->get($definition);
        $resolver->get($definition);
        self::assertSame(1, $this->provider->reads);

        $this->provider->values['chat.days'] = '46';
        $resolver->clearRuntimeCache();
        self::assertSame(46, $resolver->get($definition), 'no cache TTL: the next request reads the provider again');
        self::assertSame(2, $this->provider->reads);
    }

    // --- Wiring errors ---------------------------------------------------------------------------------------------

    #[Test]
    public function anUnregisteredProviderIsAConfigurationErrorEvenUnderFallback(): void
    {
        $definition = new RuntimeSettingDefinition('chat.days', RuntimeSettingType::Int, RuntimeSettingSource::DbThenEnv, RuntimeSettingErrorPolicy::Fallback, validator: new Unbounded(), configured: 30, provider: 'missing');

        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('"missing"');

        $this->resolver()->get($definition);
    }

    #[Test]
    public function aResolverWithoutProvidersRefusesAProviderSetting(): void
    {
        $resolver = new RuntimeSettingResolver($this->source, null, $this->clock, 0, $this->logger);

        $this->expectException(RuntimeSettingConfigurationError::class);

        $resolver->get($this->int(RuntimeSettingSource::Db));
    }

    // --- Failures --------------------------------------------------------------------------------------------------

    #[Test]
    public function aFailingProviderFailsClosedWithoutItsMessageOrChain(): void
    {
        $this->provider->failWith = new RuntimeException(self::SQL_IN_MESSAGE);

        try {
            $this->resolver()->get($this->int(RuntimeSettingSource::Db));
            self::fail('Expected an exception.');
        } catch (RuntimeSettingUnavailable $e) {
            $failure = $e->getPrevious();
            self::assertInstanceOf(RuntimeSettingSourceFailure::class, $failure);
            self::assertSame('Runtime value provider "tenant" could not be read (RuntimeException).', $failure->getMessage());
            self::assertNull($failure->getPrevious());
            self::assertStringNotContainsString('SELECT', $e->getMessage() . $failure->getMessage());
        }
    }

    #[Test]
    public function aFailingProviderFallsBackAndLogsTheClassOnly(): void
    {
        $this->provider->failWith = new RuntimeException(self::SQL_IN_MESSAGE);

        self::assertSame(30, $this->resolver()->get($this->int(RuntimeSettingSource::Db, configured: 30, onError: RuntimeSettingErrorPolicy::Fallback)));
        self::assertStringNotContainsString('SELECT', $this->logger->text());
        self::assertStringNotContainsString('REDACTED', $this->logger->text());
        self::assertSame(RuntimeSettingSourceFailure::class, $this->logger->records[0]['context']['exception']);
    }

    #[Test]
    public function anOversizedCurrentValueIsRefusedBeforeParsing(): void
    {
        $this->provider->values['chat.days'] = str_repeat('1', RuntimeSettingType::MAX_LENGTH + 1);

        try {
            $this->resolver()->get($this->int(RuntimeSettingSource::Db));
            self::fail('Expected an exception.');
        } catch (RuntimeSettingUnavailable $e) {
            self::assertStringContainsString('longer than 65536 bytes', (string) $e->getPrevious()?->getMessage());
        }
    }

    #[Test]
    public function anOversizedPreviousValueIsRefusedBeforeParsing(): void
    {
        $this->provider->values['chat.days'] = new StoredRuntimeValue('1', str_repeat('1', RuntimeSettingType::MAX_LENGTH + 1), new DateTimeImmutable('2030-01-01'));

        $this->expectException(RuntimeSettingUnavailable::class);

        $this->resolver()->get($this->int(RuntimeSettingSource::Db));
    }

    // --- Shared cache ----------------------------------------------------------------------------------------------

    #[Test]
    public function aProviderWithTtlIsSharedThroughTheCacheForOneBucket(): void
    {
        $cache                               = new Psr6RuntimeValueCache(new ArrayAdapter());
        $this->provider->values['chat.days'] = '45';
        $definition                          = $this->int(RuntimeSettingSource::Db);

        self::assertSame(45, $this->resolver($cache, 60)->get($definition));

        // Another worker within the same bucket: from the cache, even though the provider changed.
        $this->provider->values['chat.days'] = '46';
        self::assertSame(45, $this->resolver($cache, 60)->get($definition));
        self::assertSame(1, $this->provider->reads);

        // The next bucket: read again.
        $this->clock->advance('+60 seconds');
        self::assertSame(46, $this->resolver($cache, 60)->get($definition));
        self::assertSame(2, $this->provider->reads);
        self::assertSame(0, $this->source->versionReads, 'a provider never reads the source version');
    }

    #[Test]
    public function aCachedAbsenceIsSharedToo(): void
    {
        $cache      = new Psr6RuntimeValueCache(new ArrayAdapter());
        $definition = $this->int(RuntimeSettingSource::DbThenEnv, configured: 5);

        self::assertSame(5, $this->resolver($cache, 60)->get($definition));
        self::assertSame(5, $this->resolver($cache, 60)->get($definition));
        self::assertSame(1, $this->provider->reads);
    }

    #[Test]
    public function changingTheTtlChangesTheToken(): void
    {
        $cache                               = new Psr6RuntimeValueCache(new ArrayAdapter());
        $this->provider->values['chat.days'] = '45';
        $definition                          = $this->int(RuntimeSettingSource::Db);

        $this->resolver($cache, 60)->get($definition);
        $this->resolver($cache, 120)->get($definition);

        self::assertSame(2, $this->provider->reads);
    }

    #[Test]
    public function twoScopesNeverShareACachedValue(): void
    {
        $pool       = new ArrayAdapter();
        $definition = $this->int(RuntimeSettingSource::Db);
        $tenantA    = new ArrayValueProvider(['chat.days' => '10']);
        $tenantB    = new ArrayValueProvider(['chat.days' => '20']);
        $resolver   = fn (ArrayValueProvider $provider, string $scope): RuntimeSettingResolver => new RuntimeSettingResolver(
            $this->source,
            new Psr6RuntimeValueCache($pool),
            $this->clock,
            0,
            $this->logger,
            new RuntimeValueProviderRegistry([new RuntimeValueProviderBinding('tenant', $provider, 60, $scope)]),
        );

        // Two deployments sharing one pool, same provider name and TTL, different scope.
        self::assertSame(10, $resolver($tenantA, 'deployment-a')->get($definition));
        self::assertSame(20, $resolver($tenantB, 'deployment-b')->get($definition));
        self::assertSame(20, $resolver($tenantB, 'deployment-b')->get($definition));
        self::assertSame(1, $tenantB->reads, 'same scope: from the cache');
        // One item per id: B's entry replaced A's, so A reads its provider again, never B's value.
        self::assertSame(10, $resolver($tenantA, 'deployment-a')->get($definition));
        self::assertSame(2, $tenantA->reads);
    }

    #[Test]
    public function aProviderWithoutTtlNeverUsesTheCache(): void
    {
        $pool                                = new ArrayAdapter();
        $this->provider->values['chat.days'] = '45';

        self::assertSame(45, $this->resolver(new Psr6RuntimeValueCache($pool), 0)->get($this->int(RuntimeSettingSource::Db)));
        self::assertSame([], $pool->getValues());
    }

    #[Test]
    public function secretsFromAProviderNeverReachTheSharedCache(): void
    {
        $pool                                = new ArrayAdapter();
        $this->provider->values['API_TOKEN'] = 'REDACTED-TEST-SECRET';
        $definition                          = new RuntimeSettingDefinition('API_TOKEN', RuntimeSettingType::String, RuntimeSettingSource::Db, secret: true, validator: new Unbounded(), provider: 'tenant');

        self::assertSame('REDACTED-TEST-SECRET', $this->resolver(new Psr6RuntimeValueCache($pool), 600)->get($definition));
        self::assertSame([], $pool->getValues());
    }

    // --- Writes ----------------------------------------------------------------------------------------------------

    #[Test]
    public function theUpdaterRefusesEveryWriteToAProviderSetting(): void
    {
        $store      = new InMemoryRuntimeSettingStore($this->clock);
        $definition = $this->int(RuntimeSettingSource::Db);
        $updater    = new RuntimeSettingUpdater(new RuntimeSettingRegistry([$definition]), $store, $this->resolver(), new RuntimeValueFingerprinter(str_repeat('k', 32)), clock: $this->clock);

        foreach ([
            static fn (): RuntimeSettingChange => $updater->set('chat.days', '5', 'cli:test'),
            static fn (): RuntimeSettingChange => $updater->set('chat.days', '5', 'cli:test', dryRun: true),
            static fn (): RuntimeSettingChange => $updater->unset('chat.days', 'cli:test'),
            static fn (): RuntimeSettingChange => $updater->rotate('chat.days', '5', new DateInterval('PT1H'), 'cli:test'),
            static fn (): string => $updater->prepare('chat.days', '5'),
        ] as $i => $write) {
            try {
                $write();
                self::fail('Write ' . $i . ' was accepted.');
            } catch (RuntimeSettingNotWritable $e) {
                self::assertSame('Runtime setting "chat.days" is read from the read-only provider "tenant": change the value where that provider reads it.', $e->getMessage());
            }
        }
        self::assertNull($store->read($definition->id));
    }

    private function resolver(?Psr6RuntimeValueCache $cache = null, int $cacheTtl = 0): RuntimeSettingResolver
    {
        return new RuntimeSettingResolver(
            $this->source,
            $cache,
            $this->clock,
            0,
            $this->logger,
            new RuntimeValueProviderRegistry([new RuntimeValueProviderBinding('tenant', $this->provider, $cacheTtl)]),
        );
    }

    private function int(
        RuntimeSettingSource $source,
        mixed $configured = null,
        mixed $default = null,
        RuntimeSettingErrorPolicy $onError = RuntimeSettingErrorPolicy::FailClosed,
    ): RuntimeSettingDefinition {
        return new RuntimeSettingDefinition('chat.days', RuntimeSettingType::Int, $source, $onError, validator: new Unbounded(), configured: $configured, default: $default, provider: 'tenant');
    }
}
