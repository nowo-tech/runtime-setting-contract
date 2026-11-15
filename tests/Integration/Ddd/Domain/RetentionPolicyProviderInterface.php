<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Integration\Ddd\Domain;

/**
 * A bundle's typed port, as the domain sees it: plain PHP, no framework, no contract types.
 */
interface RetentionPolicyProviderInterface
{
    public function retentionDays(): ?int;
}
