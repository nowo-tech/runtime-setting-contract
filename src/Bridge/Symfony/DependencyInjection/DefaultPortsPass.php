<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection;

use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriterInterface;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueSourceInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Binds the source and writer ports to the "unconfigured" store only when nothing else did: the bundle config
 * (`source:` / `writer:`), the host (an alias or a service under the interface id) or an adapter bundle.
 */
final class DefaultPortsPass implements CompilerPassInterface
{
    public const UNCONFIGURED_ID = 'nowo_runtime_setting.unconfigured_store';

    public function process(ContainerBuilder $container): void
    {
        foreach ([RuntimeValueSourceInterface::class, RuntimeSettingWriterInterface::class] as $port) {
            if (!$container->has($port)) {
                $container->setAlias($port, self::UNCONFIGURED_ID);
            }
        }
    }
}
