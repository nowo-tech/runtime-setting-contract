<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Bridge\Symfony;

use Nowo\RuntimeSettingContract\Bridge\Symfony\Cache\Psr6RuntimeValueCache;
use Nowo\RuntimeSettingContract\Bridge\Symfony\Command\GetRuntimeSettingCommand;
use Nowo\RuntimeSettingContract\Bridge\Symfony\Command\ListRuntimeSettingsCommand;
use Nowo\RuntimeSettingContract\Bridge\Symfony\Command\RotateRuntimeSettingCommand;
use Nowo\RuntimeSettingContract\Bridge\Symfony\Command\SetRuntimeSettingCommand;
use Nowo\RuntimeSettingContract\Bridge\Symfony\Command\UnsetRuntimeSettingCommand;
use Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection\DefaultPortsPass;
use Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection\RuntimeSettingsConfiguration;
use Nowo\RuntimeSettingContract\Bridge\Symfony\EventListener\RuntimeSettingBoundaryListener;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriterInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueSourceInterface;
use Nowo\RuntimeSettingContract\Contract\SystemClock;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function dirname;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * Symfony glue for nowo-tech/runtime-setting-contract: resolver, updater, boundary listener, optional PSR-6 cache
 * and the `nowo:runtime-setting:*` commands (registered only with `command.enabled: true`).
 *
 * ```yaml
 * nowo_runtime_setting:
 *     source: App\Settings\DoctrineRuntimeSettingStore   # service id; or alias RuntimeValueSourceInterface yourself
 *     writer: App\Settings\DoctrineRuntimeSettingStore
 *     cache_pool: cache.app                               # optional PSR-6 pool for non-secret values
 *     version_ttl: 5                                      # seconds a version token is reused; 0 = read every request
 *     command:
 *         enabled: false                                  # default; turn on deliberately
 * ```
 */
final class NowoRuntimeSettingBundle extends AbstractBundle
{
    protected string $extensionAlias = 'nowo_runtime_setting';

    public function getPath(): string
    {
        return dirname(__DIR__, 3);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('source')
                    ->info('Service id of the RuntimeValueSourceInterface implementation. Null: keep an existing alias, else reads fail (on_error applies).')
                    ->defaultNull()
                ->end()
                ->scalarNode('writer')
                    ->info('Service id of the RuntimeSettingWriterInterface implementation. Null: keep an existing alias, else writes are refused.')
                    ->defaultNull()
                ->end()
                ->scalarNode('cache_pool')
                    ->info('Optional PSR-6 pool service id for non-secret values (secrets are never cached).')
                    ->defaultNull()
                ->end()
                ->integerNode('cache_lifetime')
                    ->info('Seconds a cached value is kept; a version change makes it unreachable earlier.')
                    ->defaultValue(Psr6RuntimeValueCache::DEFAULT_LIFETIME)
                    ->min(1)
                    ->max(86400)
                ->end()
                ->integerNode('version_ttl')
                    ->info('Seconds a namespace version token is reused across requests: a change reaches every worker within this time. 0: read it on every request.')
                    ->defaultValue(RuntimeSettingResolver::DEFAULT_VERSION_TTL)
                    ->min(0)
                    ->max(RuntimeSettingResolver::MAX_VERSION_TTL)
                ->end()
                ->scalarNode('fingerprint_key')
                    ->info('HMAC key for value fingerprints in audit entries and command output (>= 16 bytes). Null: kernel.secret.')
                    ->defaultNull()
                ->end()
                ->arrayNode('command')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('Register the nowo:runtime-setting:* commands. Off by default.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DefaultPortsPass());
    }

    /**
     * @param array{source: ?string, writer: ?string, cache_pool: ?string, cache_lifetime: int, version_ttl: int, fingerprint_key: ?string, command: array{enabled: bool}} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set(DefaultPortsPass::UNCONFIGURED_ID, UnconfiguredRuntimeSettingStore::class);

        if ($config['source'] !== null) {
            $services->alias(RuntimeValueSourceInterface::class, $config['source']);
        }
        if ($config['writer'] !== null) {
            $services->alias(RuntimeSettingWriterInterface::class, $config['writer']);
        }

        $services->set('nowo_runtime_setting.clock', SystemClock::class);

        $cache = null;
        if ($config['cache_pool'] !== null) {
            $services->set('nowo_runtime_setting.cache', Psr6RuntimeValueCache::class)
                ->args([service($config['cache_pool']), $config['cache_lifetime'], service('logger')->nullOnInvalid()]);
            $cache = service('nowo_runtime_setting.cache');
        }

        $services->set('nowo_runtime_setting.registry', RuntimeSettingRegistry::class)
            ->args([tagged_iterator(RuntimeSettingsConfiguration::DEFINITION_TAG)]);
        $services->alias(RuntimeSettingRegistry::class, 'nowo_runtime_setting.registry');

        $services->set('nowo_runtime_setting.fingerprinter', RuntimeValueFingerprinter::class)
            ->args([$config['fingerprint_key'] ?? param('kernel.secret')]);
        $services->alias(RuntimeValueFingerprinter::class, 'nowo_runtime_setting.fingerprinter');

        $services->set('nowo_runtime_setting.resolver', RuntimeSettingResolver::class)
            ->args([
                service(RuntimeValueSourceInterface::class),
                $cache,
                service('nowo_runtime_setting.clock'),
                $config['version_ttl'],
                service('logger')->nullOnInvalid(),
            ])
            ->tag('kernel.reset', ['method' => 'reset']);
        $services->alias(RuntimeSettingResolver::class, 'nowo_runtime_setting.resolver');

        $services->set('nowo_runtime_setting.updater', RuntimeSettingUpdater::class)
            ->args([
                service('nowo_runtime_setting.registry'),
                service(RuntimeSettingWriterInterface::class),
                service('nowo_runtime_setting.resolver'),
                service('nowo_runtime_setting.fingerprinter'),
                service('event_dispatcher')->nullOnInvalid(),
                service('nowo_runtime_setting.clock'),
                service('logger')->nullOnInvalid(),
            ]);
        $services->alias(RuntimeSettingUpdater::class, 'nowo_runtime_setting.updater');

        $services->set('nowo_runtime_setting.boundary_listener', RuntimeSettingBoundaryListener::class)
            ->args([service('nowo_runtime_setting.resolver')])
            ->tag('kernel.event_subscriber')
            ->tag('kernel.reset', ['method' => 'reset']);

        if (!$config['command']['enabled']) {
            return;
        }

        $services->set('nowo_runtime_setting.command.get', GetRuntimeSettingCommand::class)
            ->args([service('nowo_runtime_setting.registry'), service('nowo_runtime_setting.resolver'), service('nowo_runtime_setting.fingerprinter')])
            ->tag('console.command');
        $services->set('nowo_runtime_setting.command.list', ListRuntimeSettingsCommand::class)
            ->args([service('nowo_runtime_setting.registry'), service('nowo_runtime_setting.resolver')])
            ->tag('console.command');
        foreach ([
            'set'    => SetRuntimeSettingCommand::class,
            'unset'  => UnsetRuntimeSettingCommand::class,
            'rotate' => RotateRuntimeSettingCommand::class,
        ] as $name => $class) {
            $services->set('nowo_runtime_setting.command.' . $name, $class)
                ->args([service('nowo_runtime_setting.updater')])
                ->tag('console.command');
        }
    }
}
