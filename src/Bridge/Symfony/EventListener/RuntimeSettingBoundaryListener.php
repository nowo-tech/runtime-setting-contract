<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Bridge\Symfony\EventListener;

use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Clears the resolver's per-request memo at every unit-of-work boundary, so FrankenPHP worker mode with the kernel
 * kept alive (no services resetter) never serves a value from a previous request:
 *
 * - main `kernel.request` (sub-requests keep the main request's values) and `kernel.terminate`;
 * - `console.command` and `console.terminate`;
 * - Messenger `WorkerMessageReceivedEvent`, `WorkerMessageHandledEvent` and `WorkerMessageFailedEvent`.
 *
 * It also implements {@see ResetInterface} (`kernel.reset`), which drops the version tokens too; it never relies on
 * that alone. Subscribing to the Messenger and Console events is harmless when those components are not installed.
 */
final readonly class RuntimeSettingBoundaryListener implements EventSubscriberInterface, ResetInterface
{
    public function __construct(
        private RuntimeSettingResolver $resolver,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST             => ['onKernelRequest', 4096],
            KernelEvents::TERMINATE           => ['onBoundary', -4096],
            ConsoleEvents::COMMAND            => ['onBoundary', 4096],
            ConsoleEvents::TERMINATE          => ['onBoundary', -4096],
            WorkerMessageReceivedEvent::class => ['onBoundary', 4096],
            WorkerMessageHandledEvent::class  => ['onBoundary', -4096],
            WorkerMessageFailedEvent::class   => ['onBoundary', -4096],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
            $this->resolver->clearRuntimeCache();
        }
    }

    public function onBoundary(): void
    {
        // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
        $this->resolver->clearRuntimeCache();
    }

    public function reset(): void
    {
        // @igor-ignore - Calls the resolver's own boundary/invalidation API (or a port); no request data kept in this service.
        $this->resolver->reset();
    }
}
