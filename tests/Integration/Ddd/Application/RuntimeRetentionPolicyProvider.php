<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Integration\Ddd\Application;

use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Tests\Integration\Ddd\Domain\RetentionPolicyProviderInterface;

use function is_int;

/**
 * Application-layer adapter: implements the domain port with the resolver.
 */
final readonly class RuntimeRetentionPolicyProvider implements RetentionPolicyProviderInterface
{
    public function __construct(
        private RuntimeSettingResolver $resolver,
        private RuntimeSettingDefinition $definition,
    ) {
    }

    public function retentionDays(): ?int
    {
        $days = $this->resolver->get($this->definition);

        return is_int($days) ? $days : null;
    }
}
