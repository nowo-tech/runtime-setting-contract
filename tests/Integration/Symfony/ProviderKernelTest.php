<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Integration\Symfony;

use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Tests\Support\ArrayValueProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * 1.1: providers wired by the Symfony bridge, in a kept-alive (worker) kernel.
 */
final class ProviderKernelTest extends TestCase
{
    use KernelTestTrait;

    #[Test]
    public function theHostBindsAnyRegisteredSettingToAConfiguredProvider(): void
    {
        $kernel = $this->kernel($this->newDatabase(), demoConfig: ['runtime' => ['mode' => ['source' => 'db', 'key' => 'DEMO_MODE']]], nowoConfig: [
            'providers'         => ['tenant' => ['service' => 'test.provider']],
            'setting_providers' => ['DEMO_MODE' => 'tenant'],
        ]);
        $container = $kernel->getContainer();
        /** @var ArrayValueProvider $provider */
        $provider = $container->get('test.provider');
        /** @var RuntimeSettingRegistry $registry */
        $registry = $container->get('test.registry');
        /** @var RuntimeSettingResolver $resolver */
        $resolver = $container->get('test.resolver');

        $provider->values['DEMO_MODE'] = 'tenant-mode';
        self::assertSame('tenant', $registry->get('DEMO_MODE')->provider);
        self::assertSame('tenant-mode', $resolver->get($registry->get('DEMO_MODE')));

        // Next request (boundary listener): the provider is read again, no restart.
        $this->request($kernel);
        $provider->values['DEMO_MODE'] = 'changed';
        self::assertSame('changed', $resolver->get($registry->get('DEMO_MODE')));

        $application = new Application($kernel);
        $set         = new CommandTester($application->find('nowo:runtime-setting:set'));
        self::assertSame(1, $set->execute(['id' => 'DEMO_MODE', 'value' => 'x']));
        self::assertStringContainsString('read-only provider', $set->getDisplay());
        self::assertStringContainsString('Nothing was', $set->getDisplay());

        $list = new CommandTester($application->find('nowo:runtime-setting:list'));
        $list->execute([]);
        self::assertStringContainsString('db via tenant', $list->getDisplay());
    }

    #[Test]
    public function aBundleSettingChoosesATaggedProvider(): void
    {
        $kernel    = $this->kernel($this->newDatabase(), demoConfig: ['runtime' => ['greeting' => ['source' => 'db_then_env', 'key' => 'demo.greeting', 'provider' => 'tagged']]]);
        $container = $kernel->getContainer();
        /** @var ArrayValueProvider $provider */
        $provider                          = $container->get('test.tagged_provider');
        $provider->values['demo.greeting'] = 'from-provider';

        self::assertSame('from-provider|stable', $this->request($kernel));
    }

    #[Test]
    public function anUnknownProviderFailsTheBuild(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('names provider "missing", which is not registered');

        $this->kernel($this->newDatabase(), demoConfig: ['runtime' => ['mode' => ['source' => 'db', 'key' => 'demo.mode', 'provider' => 'missing']]]);
    }

    #[Test]
    public function anInvalidProviderNameFailsTheConfiguration(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->kernel($this->newDatabase(), nowoConfig: ['providers' => ['Bad-Name' => ['service' => 'test.provider']]]);
    }

    #[Test]
    public function anInvalidMappedProviderNameFailsTheConfiguration(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->kernel($this->newDatabase(), nowoConfig: ['setting_providers' => ['demo.mode' => 'Bad-Name']]);
    }
}
