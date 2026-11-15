<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function __construct(private readonly ?Throwable $failWith = null)
    {
    }

    public function clear(): void
    {
        $this->events = [];
    }

    public function dispatch(object $event): object
    {
        $this->events[] = $event;
        if ($this->failWith instanceof Throwable) {
            throw $this->failWith;
        }

        return $event;
    }
}
