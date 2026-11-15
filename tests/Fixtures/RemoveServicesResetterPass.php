<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Fixtures;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * FrankenPHP worker mode with kernel reset false: no services resetter between requests (Kernel::boot() only resets
 * when the service exists), and no Messenger listener that would call it. Nothing but the bundle's own boundary
 * listener clears state.
 */
final class RemoveServicesResetterPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->removeDefinition('messenger.listener.reset_services');
        $container->removeDefinition('services_resetter');
    }
}
