<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Unit\Bridge\Symfony;

use Nowo\RuntimeSettingContract\Bridge\Symfony\EventListener\RuntimeSettingBoundaryListener;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriteContext;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\InMemory\InMemoryRuntimeSettingStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

use function str_repeat;

final class RuntimeSettingBoundaryListenerTest extends TestCase
{
    private InMemoryRuntimeSettingStore $store;

    private RuntimeSettingResolver $resolver;

    private RuntimeSettingDefinition $definition;

    private RuntimeSettingWriteContext $context;

    protected function setUp(): void
    {
        $this->store      = new InMemoryRuntimeSettingStore();
        $this->resolver   = new RuntimeSettingResolver($this->store, versionTtl: 0);
        $this->definition = new RuntimeSettingDefinition('a.b', RuntimeSettingType::String, RuntimeSettingSource::Db);
        $this->context    = new RuntimeSettingWriteContext('cli:test', new RuntimeValueFingerprinter(str_repeat('k', 16)));
    }

    #[Test]
    public function subscribesToEveryBoundary(): void
    {
        $events = RuntimeSettingBoundaryListener::getSubscribedEvents();

        foreach ([KernelEvents::REQUEST, KernelEvents::TERMINATE, ConsoleEvents::COMMAND, ConsoleEvents::TERMINATE, WorkerMessageReceivedEvent::class, WorkerMessageHandledEvent::class, WorkerMessageFailedEvent::class] as $name) {
            self::assertArrayHasKey($name, $events);
        }
    }

    #[Test]
    public function mainRequestClearsTheMemoSubRequestKeepsIt(): void
    {
        $listener = new RuntimeSettingBoundaryListener($this->resolver);
        $kernel   = $this->createStub(HttpKernelInterface::class);
        $this->store->set($this->definition, 'one', $this->context);
        self::assertSame('one', $this->resolver->get($this->definition));
        $this->store->set($this->definition, 'two', $this->context);

        $listener->onKernelRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        self::assertSame('one', $this->resolver->get($this->definition));

        $listener->onKernelRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        self::assertSame('two', $this->resolver->get($this->definition));
    }

    #[Test]
    public function otherBoundariesAndResetClearTheMemo(): void
    {
        $listener = new RuntimeSettingBoundaryListener($this->resolver);
        $this->store->set($this->definition, 'one', $this->context);
        self::assertSame('one', $this->resolver->get($this->definition));

        $this->store->set($this->definition, 'two', $this->context);
        $listener->onBoundary();
        self::assertSame('two', $this->resolver->get($this->definition));

        $this->store->set($this->definition, 'three', $this->context);
        $listener->reset();
        self::assertSame('three', $this->resolver->get($this->definition));
    }
}
