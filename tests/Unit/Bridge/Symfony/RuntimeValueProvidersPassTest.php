<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Unit\Bridge\Symfony;

use Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection\RuntimeSettingsConfiguration;
use Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection\RuntimeValueProvidersPass;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueProviderBinding;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueProviderRegistry;
use Nowo\RuntimeSettingContract\Contract\Validator\Unbounded;
use Nowo\RuntimeSettingContract\Tests\Support\ArrayValueProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class RuntimeValueProvidersPassTest extends TestCase
{
    #[Test]
    public function doesNothingWithoutTheRegistry(): void
    {
        $container = new ContainerBuilder();
        $container->register('tagged', ArrayValueProvider::class)->addTag(RuntimeSettingsConfiguration::PROVIDER_TAG);

        (new RuntimeValueProvidersPass())->process($container);

        self::assertFalse($container->hasDefinition(RuntimeValueProvidersPass::REGISTRY_ID));
    }

    #[Test]
    public function collectsConfiguredAndTaggedProvidersSortedByName(): void
    {
        $container = $this->container(configured: ['zeta' => ['service' => 'host.provider', 'cache_ttl' => 30]]);
        $container->register('adapter.provider', ArrayValueProvider::class)->addTag(RuntimeSettingsConfiguration::PROVIDER_TAG, ['name' => 'alpha', 'cache_ttl' => 60]);
        $container->register('adapter.provider2', ArrayValueProvider::class)->addTag(RuntimeSettingsConfiguration::PROVIDER_TAG, ['name' => 'beta']);

        (new RuntimeValueProvidersPass())->process($container);

        /** @var list<Definition> $bindings */
        $bindings = $container->getDefinition(RuntimeValueProvidersPass::REGISTRY_ID)->getArgument(0);
        self::assertCount(3, $bindings);
        self::assertSame(RuntimeValueProviderBinding::class, $bindings[0]->getClass());
        self::assertSame(['alpha', 'adapter.provider', 60], [$bindings[0]->getArgument(0), $this->ref($bindings[0]->getArgument(1)), $bindings[0]->getArgument(2)]);
        self::assertSame(['beta', 'adapter.provider2', 0], [$bindings[1]->getArgument(0), $this->ref($bindings[1]->getArgument(1)), $bindings[1]->getArgument(2)]);
        self::assertSame(['zeta', 'host.provider', 30], [$bindings[2]->getArgument(0), $this->ref($bindings[2]->getArgument(1)), $bindings[2]->getArgument(2)]);
        self::assertInstanceOf(Reference::class, $bindings[2]->getArgument(1));

        $container->register('host.provider', ArrayValueProvider::class);
        $container->getDefinition(RuntimeValueProvidersPass::REGISTRY_ID)->setPublic(true);
        $container->compile();
        /** @var RuntimeValueProviderRegistry $registry */
        $registry = $container->get(RuntimeValueProvidersPass::REGISTRY_ID);
        self::assertSame(['alpha', 'beta', 'zeta'], $registry->names());
    }

    #[Test]
    public function ignoresMalformedConfiguredEntriesAndParameters(): void
    {
        $container = new ContainerBuilder();
        $container->register(RuntimeValueProvidersPass::REGISTRY_ID, RuntimeValueProviderRegistry::class)->setArguments([[]]);
        $container->setParameter(RuntimeValueProvidersPass::CONFIGURED_PROVIDERS_PARAMETER, ['bad' => 'x', 'worse' => ['service' => 1, 'cache_ttl' => 0]]);
        $container->setParameter(RuntimeValueProvidersPass::SETTING_PROVIDERS_PARAMETER, 'not-a-map');

        (new RuntimeValueProvidersPass())->process($container);

        self::assertSame([], $container->getDefinition(RuntimeValueProvidersPass::REGISTRY_ID)->getArgument(0));

        $bare = new ContainerBuilder();
        $bare->register(RuntimeValueProvidersPass::REGISTRY_ID, RuntimeValueProviderRegistry::class)->setArguments([[]]);
        (new RuntimeValueProvidersPass())->process($bare);
        self::assertSame([], $bare->getDefinition(RuntimeValueProvidersPass::REGISTRY_ID)->getArgument(0));
    }

    #[Test]
    public function refusesATagWithoutAValidName(): void
    {
        $container = $this->container();
        $container->register('adapter.provider', ArrayValueProvider::class)->addTag(RuntimeSettingsConfiguration::PROVIDER_TAG, ['name' => 'Bad Name']);

        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('without a valid "name"');

        (new RuntimeValueProvidersPass())->process($container);
    }

    #[Test]
    public function refusesATagWithANonIntegerTtl(): void
    {
        $container = $this->container();
        $container->register('adapter.provider', ArrayValueProvider::class)->addTag(RuntimeSettingsConfiguration::PROVIDER_TAG, ['name' => 'tenant', 'cache_ttl' => '60']);

        $this->expectException(RuntimeSettingConfigurationError::class);

        (new RuntimeValueProvidersPass())->process($container);
    }

    #[Test]
    public function refusesATagWithAnOutOfRangeTtl(): void
    {
        $container = $this->container();
        $container->register('adapter.provider', ArrayValueProvider::class)->addTag(RuntimeSettingsConfiguration::PROVIDER_TAG, ['name' => 'tenant', 'cache_ttl' => 99999]);

        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('between 0 and 3600');

        (new RuntimeValueProvidersPass())->process($container);
    }

    #[Test]
    public function refusesTwoProvidersWithOneName(): void
    {
        $container = $this->container(configured: ['tenant' => ['service' => 'host.provider', 'cache_ttl' => 0]]);
        $container->register('adapter.provider', ArrayValueProvider::class)->addTag(RuntimeSettingsConfiguration::PROVIDER_TAG, ['name' => 'tenant']);

        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('"tenant" is registered twice');

        (new RuntimeValueProvidersPass())->process($container);
    }

    #[Test]
    public function bindsSettingsFromTheHostMap(): void
    {
        $container = $this->container(configured: ['tenant' => ['service' => 'host.provider', 'cache_ttl' => 0]], map: ['chat.days' => 'tenant']);
        $container->setParameter('days_key', 'chat.days');
        $this->registerSetting($container, 'chat.runtime.days', ['source' => 'db', 'key' => '%days_key%', 'on_error' => 'fail_closed']);
        $this->registerSetting($container, 'chat.runtime.other', ['source' => 'env', 'key' => 'chat.other', 'on_error' => 'fail_closed']);

        (new RuntimeValueProvidersPass())->process($container);

        self::assertSame('tenant', $container->getDefinition('chat.runtime.days')->getArgument('$provider'));
        self::assertNull($container->getDefinition('chat.runtime.other')->getArgument('$provider'));
    }

    #[Test]
    public function acceptsTheSameProviderFromTheBundleAndTheMap(): void
    {
        $container = $this->container(configured: ['tenant' => ['service' => 'host.provider', 'cache_ttl' => 0]], map: ['chat.days' => 'tenant']);
        $this->registerSetting($container, 'chat.runtime.days', ['source' => 'db', 'key' => 'chat.days', 'on_error' => 'fail_closed', 'provider' => 'tenant']);

        (new RuntimeValueProvidersPass())->process($container);

        self::assertSame('tenant', $container->getDefinition('chat.runtime.days')->getArgument('$provider'));
    }

    #[Test]
    public function refusesTwoDifferentProvidersForOneSetting(): void
    {
        $container = $this->container(configured: ['tenant' => ['service' => 'a', 'cache_ttl' => 0], 'other' => ['service' => 'b', 'cache_ttl' => 0]], map: ['chat.days' => 'other']);
        $this->registerSetting($container, 'chat.runtime.days', ['source' => 'db', 'key' => 'chat.days', 'on_error' => 'fail_closed', 'provider' => 'tenant']);

        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('keep one');

        (new RuntimeValueProvidersPass())->process($container);
    }

    #[Test]
    public function refusesAMappedSettingWithSourceEnv(): void
    {
        $container = $this->container(configured: ['tenant' => ['service' => 'a', 'cache_ttl' => 0]], map: ['chat.days' => 'tenant']);
        $this->registerSetting($container, 'chat.runtime.days', ['source' => 'env', 'key' => 'chat.days', 'on_error' => 'fail_closed']);

        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('"chat.days" is bound to provider "tenant" but uses "source: env"');

        (new RuntimeValueProvidersPass())->process($container);
    }

    #[Test]
    public function refusesASettingWithoutSourceArgumentBoundToAProvider(): void
    {
        $container = $this->container(configured: ['tenant' => ['service' => 'a', 'cache_ttl' => 0]]);
        $container->register('odd.definition', stdClass::class)->setArguments(['$provider' => 'tenant'])->addTag(RuntimeSettingsConfiguration::DEFINITION_TAG);

        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('"odd.definition" is bound');

        (new RuntimeValueProvidersPass())->process($container);
    }

    #[Test]
    public function refusesAnUnregisteredProvider(): void
    {
        $container = $this->container(configured: ['tenant' => ['service' => 'a', 'cache_ttl' => 0]]);
        $this->registerSetting($container, 'chat.runtime.days', ['source' => 'db', 'key' => 'chat.days', 'on_error' => 'fail_closed', 'provider' => 'missing']);

        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('names provider "missing", which is not registered. Registered providers: tenant.');

        (new RuntimeValueProvidersPass())->process($container);
    }

    #[Test]
    public function refusesAProviderWhenNoneIsRegistered(): void
    {
        $container = $this->container();
        $this->registerSetting($container, 'chat.runtime.days', ['source' => 'db', 'key' => 'chat.days', 'on_error' => 'fail_closed', 'provider' => 'missing']);

        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('Registered providers: none.');

        (new RuntimeValueProvidersPass())->process($container);
    }

    #[Test]
    public function refusesANonStringProviderArgument(): void
    {
        $container = $this->container();
        $container->register('odd.definition', stdClass::class)
            ->setArguments(['$id' => 'chat.days', '$source' => RuntimeSettingSource::Db, '$provider' => 12])
            ->addTag(RuntimeSettingsConfiguration::DEFINITION_TAG);

        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('names provider "?"');

        (new RuntimeValueProvidersPass())->process($container);
    }

    #[Test]
    public function refusesAMapEntryForAnUnknownSetting(): void
    {
        $container = $this->container(configured: ['tenant' => ['service' => 'a', 'cache_ttl' => 0]], map: ['chat.unknown' => 'tenant', 'chat.days' => 'tenant']);
        $this->registerSetting($container, 'chat.runtime.days', ['source' => 'db', 'key' => 'chat.days', 'on_error' => 'fail_closed']);

        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('names settings no bundle registered: chat.unknown.');

        (new RuntimeValueProvidersPass())->process($container);
    }

    private function ref(mixed $reference): string
    {
        self::assertInstanceOf(Reference::class, $reference);

        return (string) $reference;
    }

    /**
     * @param array<string, array{service: string, cache_ttl: int}> $configured
     * @param array<string, string> $map
     */
    private function container(array $configured = [], array $map = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(RuntimeValueProvidersPass::REGISTRY_ID, RuntimeValueProviderRegistry::class)->setArguments([[]]);
        $container->setParameter(RuntimeValueProvidersPass::CONFIGURED_PROVIDERS_PARAMETER, $configured);
        $container->setParameter(RuntimeValueProvidersPass::SETTING_PROVIDERS_PARAMETER, $map);
        $container->register('validator', Unbounded::class);

        return $container;
    }

    /**
     * @param array{source: string, key: string, on_error: string, provider?: string|null} $runtime
     */
    private function registerSetting(ContainerBuilder $container, string $serviceId, array $runtime): void
    {
        RuntimeSettingsConfiguration::registerDefinition($container, $serviceId, $runtime, RuntimeSettingType::Int, null, validatorServiceId: 'validator');
    }
}
