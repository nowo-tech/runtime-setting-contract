<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Replaces the services resetter with a no-op: nothing but the bundle's own boundary listener clears state.
 */
final class RemoveServicesResetterPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->removeDefinition('services_resetter');
        $container->register('services_resetter', NoopServicesResetter::class)->setPublic(true);
    }
}
