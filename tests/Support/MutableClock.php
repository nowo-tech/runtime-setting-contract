<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class MutableClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2026-11-16 12:00:00.000000')
    {
        $this->now = new DateTimeImmutable($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
