<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection;

use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingErrorPolicy;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_column;
use function array_map;
use function is_string;

/**
 * Helpers a bundle reuses to offer `runtime:` configuration for its settings and to register their definitions.
 *
 * ```php
 * // Configuration (tree builder)
 * RuntimeSettingsConfiguration::appendTo($rootNode, [
 *     'retention_days' => ['key' => 'chat.retention_days', 'on_error' => 'fail_closed'],
 * ]);
 *
 * // Extension::load() / AbstractBundle::loadExtension()
 * RuntimeSettingsConfiguration::registerDefinition(
 *     $container, 'nowo_chat_kit.runtime_setting.retention_days', $config['runtime']['retention_days'],
 *     RuntimeSettingType::Int, $config['retention_days'], validatorServiceId: ChatRetentionValidator::class,
 * );
 * ```
 *
 * which accepts, in the host's configuration:
 *
 * ```yaml
 * nowo_chat_kit:
 *     runtime:
 *         retention_days: { source: db_then_env, key: chat.retention_days, on_error: fail_closed }
 * ```
 */
final class RuntimeSettingsConfiguration
{
    public const DEFINITION_TAG = 'nowo_runtime_setting.definition';

    /**
     * Adds a `runtime` node with one child per setting: `source` (default `env`), `key` and `on_error`.
     *
     * @param array<string, array{key: string, on_error?: 'fail_closed'|'fallback', source?: 'db'|'db_then_env'|'env'}> $settings
     *                                                                                                                            defaults per setting name
     */
    public static function appendTo(ArrayNodeDefinition $root, array $settings): void
    {
        $sources  = array_map(static fn (RuntimeSettingSource $s): string => $s->value, RuntimeSettingSource::cases());
        $policies = array_column(RuntimeSettingErrorPolicy::cases(), 'value');

        $children = $root->children()
            ->arrayNode('runtime')
                ->info('Where each setting comes from at runtime (nowo-tech/runtime-setting-contract). Default: the configured (env) value only.')
                ->addDefaultsIfNotSet()
                ->children();

        foreach ($settings as $name => $defaults) {
            $children
                ->arrayNode($name)
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('source')
                            ->info('env: configured value only; db: the runtime setting source only; db_then_env: the source, else the configured value.')
                            ->values($sources)
                            ->defaultValue($defaults['source'] ?? RuntimeSettingSource::Env->value)
                        ->end()
                        ->scalarNode('key')
                            ->info('Id in the source: "namespace.key" or an environment variable name.')
                            ->defaultValue($defaults['key'])
                            ->cannotBeEmpty()
                            ->validate()
                                ->ifTrue(static fn (mixed $key): bool => !is_string($key) || !self::isValidId($key))
                                ->thenInvalid('Invalid runtime setting key %s: use "namespace.key" (lowercase) or an environment variable name.')
                            ->end()
                        ->end()
                        ->enumNode('on_error')
                            ->info('When the source fails or holds an invalid value: fallback (warn, use the configured value) or fail_closed (abort).')
                            ->values($policies)
                            ->defaultValue($defaults['on_error'] ?? RuntimeSettingErrorPolicy::FailClosed->value)
                        ->end()
                    ->end()
                ->end();
        }
    }

    /**
     * Registers one {@see RuntimeSettingDefinition} service, tagged so the registry picks it up.
     *
     * @param array{source: string, key: string, on_error: string} $runtime the processed `runtime.<setting>` node
     * @param mixed $configured the configured (env) value, may be an `%env()%` placeholder
     *
     * @throws RuntimeSettingConfigurationError when the host chose a writable source and the bundle passes no validator
     */
    public static function registerDefinition(
        ContainerBuilder $container,
        string $serviceId,
        array $runtime,
        RuntimeSettingType $type,
        mixed $configured,
        bool $secret = false,
        ?string $validatorServiceId = null,
        mixed $default = null,
        bool $required = false,
        ?int $maxLength = null,
    ): Definition {
        // Fail at container build, not at the first request (audit QS-M-2).
        if ($validatorServiceId === null && $runtime['source'] !== RuntimeSettingSource::Env->value) {
            throw RuntimeSettingConfigurationError::because('Runtime setting "' . $runtime['key'] . '" (' . $serviceId . ') uses "source: ' . $runtime['source'] . '" but its bundle registers no validator. Pass validatorServiceId (IntRange, SingleLineString, your own validator, or Unbounded explicitly).');
        }

        $definition = new Definition(RuntimeSettingDefinition::class, [
            '$id'         => $runtime['key'],
            '$type'       => $type,
            '$source'     => RuntimeSettingSource::from($runtime['source']),
            '$onError'    => RuntimeSettingErrorPolicy::from($runtime['on_error']),
            '$secret'     => $secret,
            '$validator'  => $validatorServiceId === null ? null : new Reference($validatorServiceId),
            '$configured' => $configured,
            '$default'    => $default,
            '$required'   => $required,
            '$maxLength'  => $maxLength,
        ]);
        $definition->addTag(self::DEFINITION_TAG);
        $container->setDefinition($serviceId, $definition);

        return $definition;
    }

    private static function isValidId(string $key): bool
    {
        try {
            RuntimeSettingId::fromString($key);

            return true;
        } catch (InvalidRuntimeSettingId) {
            return false;
        }
    }
}
