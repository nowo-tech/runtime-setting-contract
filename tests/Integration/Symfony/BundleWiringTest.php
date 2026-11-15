<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Integration\Symfony;

use Nowo\RuntimeSettingContract\Bridge\Symfony\NowoRuntimeSettingBundle;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function dirname;

final class BundleWiringTest extends TestCase
{
    use KernelTestTrait;

    #[Test]
    public function commandsAreNotRegisteredByDefault(): void
    {
        $application = new Application($this->kernel($this->newDatabase(), commandEnabled: false));

        self::assertFalse($application->has('nowo:runtime-setting:set'));
        self::assertFalse($application->has('nowo:runtime-setting:get'));
    }

    #[Test]
    public function commandsAreRegisteredWhenEnabled(): void
    {
        $application = new Application($this->kernel($this->newDatabase()));

        foreach (['get', 'set', 'unset', 'list', 'rotate', 'purge'] as $name) {
            self::assertTrue($application->has('nowo:runtime-setting:' . $name), $name);
        }
    }

    #[Test]
    public function definitionsComeFromTheAdoptingBundlesConfiguration(): void
    {
        $kernel = $this->kernel($this->newDatabase(), demoConfig: [
            'greeting' => 'bonjour',
            'runtime'  => ['mode' => ['source' => 'db', 'key' => 'DEMO_MODE']],
        ]);
        /** @var RuntimeSettingRegistry $registry */
        $registry = $kernel->getContainer()->get('test.registry');

        self::assertSame('bonjour', $registry->get('demo.greeting')->configuredValue());
        self::assertSame(RuntimeSettingSource::Db, $registry->get('DEMO_MODE')->source);
        self::assertTrue($registry->get('DEMO_TOKEN')->secret);
        self::assertSame(10, $registry->get('demo.limit')->defaultValue());
    }

    #[Test]
    public function theCommandWritesAndTheRequestReadsInTheSameKernel(): void
    {
        $kernel      = $this->kernel($this->newDatabase());
        $application = new Application($kernel);
        self::assertSame('hello|stable', $this->request($kernel));

        $tester = new CommandTester($application->find('nowo:runtime-setting:set'));
        self::assertSame(0, $tester->execute(['id' => 'demo.greeting', 'value' => 'hola']));

        self::assertSame('hola|stable', $this->request($kernel));
    }

    #[Test]
    public function theSharedCachePoolIsWired(): void
    {
        $kernel = $this->kernel($this->newDatabase(), cachePool: 'test.array_pool');
        self::assertSame('hello|stable', $this->request($kernel));
        self::assertTrue($kernel->getContainer()->has('test.resolver'));
        self::assertInstanceOf(RuntimeSettingResolver::class, $kernel->getContainer()->get('test.resolver'));
    }

    #[Test]
    public function bundlePathIsThePackageRoot(): void
    {
        self::assertSame(dirname(__DIR__, 3), (new NowoRuntimeSettingBundle())->getPath());
    }
}
