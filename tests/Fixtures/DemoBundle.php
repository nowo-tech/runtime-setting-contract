<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Fixtures;

use Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection\RuntimeSettingsConfiguration;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * An adopting bundle, as a bundle author writes it with the config tree helper.
 */
final class DemoBundle extends AbstractBundle
{
    protected string $extensionAlias = 'demo';

    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();
        $root->children()
            ->scalarNode('greeting')->defaultValue('hello')->end()
            ->scalarNode('token')->defaultNull()->end()
            ->scalarNode('limit')->defaultValue(10)->end()
            ->scalarNode('mode')->defaultValue('normal')->end()
        ->end();

        RuntimeSettingsConfiguration::appendTo($root, [
            'greeting' => ['key' => 'demo.greeting', 'on_error' => 'fallback', 'source' => 'db_then_env'],
            'token'    => ['key' => 'DEMO_TOKEN', 'source' => 'db'],
            'limit'    => ['key' => 'demo.limit'],
            'mode'     => ['key' => 'demo.mode'],
        ]);
    }

    /**
     * @param array{greeting: string, token: ?string, limit: int|string, mode: string, runtime: array<string, array{source: string, key: string, on_error: string}>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()->set(DemoLimitValidator::class);

        RuntimeSettingsConfiguration::registerDefinition($builder, 'demo.runtime_setting.greeting', $config['runtime']['greeting'], RuntimeSettingType::String, $config['greeting']);
        RuntimeSettingsConfiguration::registerDefinition($builder, 'demo.runtime_setting.token', $config['runtime']['token'], RuntimeSettingType::String, $config['token'], secret: true);
        RuntimeSettingsConfiguration::registerDefinition($builder, 'demo.runtime_setting.limit', $config['runtime']['limit'], RuntimeSettingType::Int, $config['limit'], validatorServiceId: DemoLimitValidator::class, default: 10, required: true);
        RuntimeSettingsConfiguration::registerDefinition($builder, 'demo.runtime_setting.mode', $config['runtime']['mode'], RuntimeSettingType::String, $config['mode']);
    }
}
