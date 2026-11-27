<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection;

use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueProviderBinding;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_diff;
use function array_keys;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function ksort;
use function sprintf;

use const SORT_STRING;

/**
 * 1.1: builds the provider registry and checks, at container build, that every setting names a registered provider.
 *
 * Providers come from the bundle configuration (`providers.<name>: { service, cache_ttl }`) and from services tagged
 * {@see RuntimeSettingsConfiguration::PROVIDER_TAG} with a `name` (and an optional `cache_ttl`), as an adapter bundle
 * (nowo-tech/runtime-env-bundle's DBAL query provider) registers them. The host binds any registered setting to a
 * provider with `setting_providers: { <id>: <name> }`, without waiting for the bundle that owns the setting to offer a
 * `provider` option. Every mistake fails the build: unknown provider, unknown setting id, `source: env`, two names.
 */
final class RuntimeValueProvidersPass implements CompilerPassInterface
{
    public const REGISTRY_ID = 'nowo_runtime_setting.provider_registry';

    public const CONFIGURED_PROVIDERS_PARAMETER = 'nowo_runtime_setting.configured_providers';

    public const SETTING_PROVIDERS_PARAMETER = 'nowo_runtime_setting.setting_providers';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::REGISTRY_ID)) {
            return;
        }

        $providers = $this->providers($container);

        $bindings = [];
        foreach ($providers as $name => [$serviceId, $ttl]) {
            // Validates name and TTL now, with the contract's own rules (the binding is built again at runtime).
            RuntimeValueProviderBinding::assertValid($name, $ttl);
            $bindings[] = new Definition(RuntimeValueProviderBinding::class, [$name, new Reference($serviceId), $ttl]);
        }
        $container->getDefinition(self::REGISTRY_ID)->setArgument(0, $bindings);

        $this->bindSettings($container, $providers);
    }

    /**
     * @return array<string, array{0: string, 1: int}> service id and cache TTL by provider name
     */
    private function providers(ContainerBuilder $container): array
    {
        $providers = [];

        $configured = $container->hasParameter(self::CONFIGURED_PROVIDERS_PARAMETER) ? $container->getParameter(self::CONFIGURED_PROVIDERS_PARAMETER) : [];
        foreach (is_array($configured) ? $configured : [] as $name => $provider) {
            if (is_array($provider) && is_string($provider['service'] ?? null) && is_int($provider['cache_ttl'] ?? null)) {
                $providers[(string) $name] = [$provider['service'], $provider['cache_ttl']];
            }
        }

        foreach ($container->findTaggedServiceIds(RuntimeSettingsConfiguration::PROVIDER_TAG) as $serviceId => $tags) {
            foreach ($tags as $tag) {
                $name = is_array($tag) ? ($tag['name'] ?? null) : null;
                $ttl  = is_array($tag) ? ($tag['cache_ttl'] ?? 0) : 0;
                if (!is_string($name) || !RuntimeValueProviderBinding::isValidName($name) || !is_int($ttl)) {
                    throw RuntimeSettingConfigurationError::because(sprintf('Service "%s" is tagged "%s" without a valid "name" (lowercase snake case) or with a non-integer "cache_ttl".', $serviceId, RuntimeSettingsConfiguration::PROVIDER_TAG));
                }
                if (isset($providers[$name])) {
                    throw RuntimeSettingConfigurationError::because(sprintf('Runtime value provider "%s" is registered twice.', $name));
                }
                $providers[$name] = [$serviceId, $ttl];
            }
        }

        ksort($providers, SORT_STRING);

        return $providers;
    }

    /**
     * @param array<string, array{0: string, 1: int}> $providers
     */
    private function bindSettings(ContainerBuilder $container, array $providers): void
    {
        $map = $container->hasParameter(self::SETTING_PROVIDERS_PARAMETER) ? $container->getParameter(self::SETTING_PROVIDERS_PARAMETER) : [];
        $map = is_array($map) ? $map : [];

        $bound = [];
        foreach (array_keys($container->findTaggedServiceIds(RuntimeSettingsConfiguration::DEFINITION_TAG)) as $serviceId) {
            $definition = $container->getDefinition($serviceId);
            $arguments  = $definition->getArguments();
            $id         = isset($arguments['$id']) ? $container->getParameterBag()->resolveValue($arguments['$id']) : null;
            $provider   = $arguments['$provider'] ?? null;
            $mapped     = is_string($id) && is_string($map[$id] ?? null) ? $map[$id] : null;

            if (is_string($id) && $mapped !== null) {
                if ($provider !== null && $provider !== $mapped) {
                    throw RuntimeSettingConfigurationError::because(sprintf('Runtime setting "%s" names provider "%s" in its bundle configuration and "%s" in nowo_runtime_setting.setting_providers: keep one.', $id, is_string($provider) ? $provider : '?', $mapped));
                }
                $provider = $mapped;
                $definition->setArgument('$provider', $provider);
                $bound[] = $id;
            }

            if ($provider === null) {
                continue;
            }
            $label = is_string($id) ? $id : $serviceId;
            $name  = is_string($provider) ? $provider : '?';
            if (($arguments['$source'] ?? RuntimeSettingSource::Env) === RuntimeSettingSource::Env) {
                throw RuntimeSettingConfigurationError::because(sprintf('Runtime setting "%s" is bound to provider "%s" but uses "source: env", which reads no provider. Use "source: db" or "db_then_env".', $label, $name));
            }
            if (!isset($providers[$name])) {
                throw RuntimeSettingConfigurationError::because(sprintf('Runtime setting "%s" names provider "%s", which is not registered. Registered providers: %s.', $label, $name, $providers === [] ? 'none' : implode(', ', array_keys($providers))));
            }
        }

        $unknown = array_diff(array_keys($map), $bound);
        if ($unknown !== []) {
            throw RuntimeSettingConfigurationError::because(sprintf('nowo_runtime_setting.setting_providers names settings no bundle registered: %s.', implode(', ', $unknown)));
        }
    }
}
