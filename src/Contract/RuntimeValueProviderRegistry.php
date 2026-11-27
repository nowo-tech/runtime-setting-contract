<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;

use function array_keys;
use function ksort;

use const SORT_STRING;

/**
 * 1.1: the named {@see RuntimeValueProviderInterface} instances a setting can choose with `provider: <name>`.
 *
 * Built once (from the container's tagged providers), immutable afterwards.
 */
final readonly class RuntimeValueProviderRegistry
{
    /** @var array<string, RuntimeValueProviderBinding> */
    private array $bindings;

    /**
     * @param iterable<RuntimeValueProviderBinding> $bindings
     *
     * @throws RuntimeSettingConfigurationError when two providers share a name
     */
    public function __construct(iterable $bindings = [])
    {
        $byName = [];
        foreach ($bindings as $binding) {
            if (isset($byName[$binding->name])) {
                throw RuntimeSettingConfigurationError::because('Runtime value provider "' . $binding->name . '" is registered twice.');
            }
            $byName[$binding->name] = $binding;
        }
        ksort($byName, SORT_STRING);
        $this->bindings = $byName;
    }

    public function has(string $name): bool
    {
        return isset($this->bindings[$name]);
    }

    /**
     * @throws RuntimeSettingConfigurationError when no provider has that name
     */
    public function get(string $name): RuntimeValueProviderBinding
    {
        if (!isset($this->bindings[$name])) {
            throw RuntimeSettingConfigurationError::unknownProvider($name);
        }

        return $this->bindings[$name];
    }

    /**
     * @return list<string> sorted
     */
    public function names(): array
    {
        return array_keys($this->bindings);
    }
}
