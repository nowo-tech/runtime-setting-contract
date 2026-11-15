<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Fixtures;

use Symfony\Component\HttpKernel\DependencyInjection\ServicesResetterInterface;

/**
 * Stands in for the services resetter: FrankenPHP worker mode with kernel reset false resets nothing.
 */
final class NoopServicesResetter implements ServicesResetterInterface
{
    public int $calls = 0;

    public function reset(): void
    {
        ++$this->calls;
    }
}
