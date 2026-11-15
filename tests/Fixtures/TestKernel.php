<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Fixtures;

use Nowo\RuntimeSettingContract\Bridge\Symfony\NowoRuntimeSettingBundle;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Nowo\RuntimeSettingContract\Tests\Support\MutableClock;
use Nowo\RuntimeSettingContract\Tests\Support\PdoRuntimeSettingStore;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function hash;

/**
 * A host application kept alive like a FrankenPHP worker with kernel reset false: the services resetter is removed,
 * so nothing but the bundle's own boundary listener clears per-request state.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $demoConfig
     */
    public function __construct(
        private readonly string $dsn,
        private readonly int $versionTtl = 0,
        private readonly bool $commandEnabled = true,
        private readonly bool $mutableClock = false,
        private readonly array $demoConfig = [],
        private readonly ?string $cachePool = null,
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new NowoRuntimeSettingBundle();
        yield new DemoBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return __DIR__ . '/var/cache/' . hash('xxh128', serialize([$this->dsn, $this->versionTtl, $this->commandEnabled, $this->mutableClock, $this->demoConfig, $this->cachePool]));
    }

    public function getLogDir(): string
    {
        return __DIR__ . '/var/log';
    }

    public function valueAction(): Response
    {
        $container = $this->getContainer();
        /** @var RuntimeSettingRegistry $registry */
        $registry = $container->get('test.registry');
        /** @var RuntimeSettingResolver $resolver */
        $resolver = $container->get('test.resolver');

        $first  = $resolver->get($registry->get('demo.greeting'));
        $second = $resolver->get($registry->get('demo.greeting'));

        return new Response($first . '|' . ($first === $second ? 'stable' : 'unstable'));
    }

    protected function build(ContainerBuilder $container): void
    {
        // FrankenPHP worker mode with kernel reset false: no services resetter between requests.
        $container->addCompilerPass(new RemoveServicesResetterPass());
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret'                => 'test-secret-for-fingerprints-0123456789',
            'test'                  => true,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'router'                => ['utf8' => true],
        ]);

        $nowo = [
            'source'      => PdoRuntimeSettingStore::class,
            'writer'      => PdoRuntimeSettingStore::class,
            'version_ttl' => $this->versionTtl,
            'command'     => ['enabled' => $this->commandEnabled],
        ];
        if ($this->cachePool !== null) {
            $nowo['cache_pool'] = $this->cachePool;
        }
        $container->extension('nowo_runtime_setting', $nowo);
        $container->extension('demo', $this->demoConfig);

        $services = $container->services();
        $services->set(PdoRuntimeSettingStore::class)->args([$this->dsn])->public();
        $services->alias('test.registry', RuntimeSettingRegistry::class)->public();
        $services->alias('test.resolver', RuntimeSettingResolver::class)->public();
        $services->alias('test.updater', RuntimeSettingUpdater::class)->public();
        if ($this->mutableClock) {
            $services->set('nowo_runtime_setting.clock', MutableClock::class)->public();
        }
        $services->set('test.array_pool', ArrayAdapter::class)->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('value', '/value')->controller('kernel::valueAction');
    }

    public static function fixturesDir(): string
    {
        return __DIR__ . '/../Fixtures';
    }
}
