<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Wall clock used when the host does not inject a PSR-20 clock.
 */
final readonly class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
