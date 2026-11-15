<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\Exception\UnknownRuntimeSetting;

use function array_filter;
use function array_values;
use function ksort;
use function strlen;

use const SORT_STRING;

/**
 * The settings that bundles registered. Only these can be read by id or written.
 *
 * Built once (from the container's tagged definitions), immutable afterwards.
 */
final readonly class RuntimeSettingRegistry
{
    /** @var array<string, RuntimeSettingDefinition> */
    private array $definitions;

    /**
     * @param iterable<RuntimeSettingDefinition> $definitions
     *
     * @throws RuntimeSettingConfigurationError when two definitions share an id
     */
    public function __construct(iterable $definitions)
    {
        $byId = [];
        foreach ($definitions as $definition) {
            $id = $definition->idString();
            if (isset($byId[$id])) {
                throw RuntimeSettingConfigurationError::duplicate($id);
            }
            $byId[$id] = $definition;
        }
        ksort($byId, SORT_STRING);
        $this->definitions = $byId;
    }

    public function has(string $id): bool
    {
        return strlen($id) <= RuntimeSettingId::MAX_LENGTH && isset($this->definitions[$id]);
    }

    /**
     * @throws UnknownRuntimeSetting
     */
    public function get(string $id): RuntimeSettingDefinition
    {
        if (!$this->has($id)) {
            throw UnknownRuntimeSetting::forId(RuntimeSettingId::fromString($id)->toString());
        }

        return $this->definitions[$id];
    }

    /**
     * @return list<RuntimeSettingDefinition> sorted by id
     */
    public function all(?string $namespace = null): array
    {
        if ($namespace === null) {
            return array_values($this->definitions);
        }

        return array_values(array_filter(
            $this->definitions,
            static fn (RuntimeSettingDefinition $definition): bool => $definition->namespace() === $namespace,
        ));
    }
}
