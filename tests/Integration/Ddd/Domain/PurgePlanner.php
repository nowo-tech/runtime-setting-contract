<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Integration\Ddd\Domain;

use DateInterval;
use DateTimeImmutable;

final readonly class PurgePlanner
{
    public function __construct(private RetentionPolicyProviderInterface $retention)
    {
    }

    public function cutoff(DateTimeImmutable $now): ?DateTimeImmutable
    {
        $days = $this->retention->retentionDays();

        return $days === null ? null : $now->sub(new DateInterval('P' . $days . 'D'));
    }
}
