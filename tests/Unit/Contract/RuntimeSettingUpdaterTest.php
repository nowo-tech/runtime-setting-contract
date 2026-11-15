<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Unit\Contract;

use DateInterval;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingNotWritable;
use Nowo\RuntimeSettingContract\Contract\Exception\UnknownRuntimeSetting;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingAction;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingUpdater;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\Contract\SettingChangedEvent;
use Nowo\RuntimeSettingContract\InMemory\InMemoryRuntimeSettingStore;
use Nowo\RuntimeSettingContract\Tests\Fixtures\DemoLimitValidator;
use Nowo\RuntimeSettingContract\Tests\Support\MutableClock;
use Nowo\RuntimeSettingContract\Tests\Support\PdoRuntimeSettingStore;
use Nowo\RuntimeSettingContract\Tests\Support\RecordingDispatcher;
use Nowo\RuntimeSettingContract\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function str_repeat;

final class RuntimeSettingUpdaterTest extends TestCase
{
    private MutableClock $clock;

    private InMemoryRuntimeSettingStore $store;

    private RuntimeSettingResolver $resolver;

    private RecordingDispatcher $dispatcher;

    private RuntimeSettingRegistry $registry;

    protected function setUp(): void
    {
        $this->clock      = new MutableClock();
        $this->store      = new InMemoryRuntimeSettingStore($this->clock);
        $this->resolver   = new RuntimeSettingResolver($this->store, null, $this->clock, 300);
        $this->dispatcher = new RecordingDispatcher();
        $this->registry   = new RuntimeSettingRegistry([
            new RuntimeSettingDefinition('chat.days', RuntimeSettingType::Int, RuntimeSettingSource::DbThenEnv, validator: new DemoLimitValidator(), configured: 30),
            new RuntimeSettingDefinition('chat.mode', RuntimeSettingType::String),
            new RuntimeSettingDefinition('API_TOKEN', RuntimeSettingType::String, RuntimeSettingSource::Db, secret: true, maxLength: 64),
        ]);
    }

    #[Test]
    public function setValidatesWritesForgetsAndPublishesAfterCommit(): void
    {
        $definition = $this->registry->get('chat.days');
        self::assertSame(30, $this->resolver->get($definition));

        $change = $this->updater()->set('chat.days', '45', 'cli:test');

        self::assertSame(RuntimeSettingAction::Set, $change->action);
        self::assertNull($change->oldFingerprint);
        self::assertNotNull($change->newFingerprint);
        self::assertSame(45, $this->resolver->get($definition), 'this process sees its own write at once (TTL 300)');
        self::assertCount(1, $this->dispatcher->events);
        $event = $this->dispatcher->events[0];
        self::assertInstanceOf(SettingChangedEvent::class, $event);
        self::assertSame('chat', $event->namespace);
        self::assertSame('days', $event->key);
        self::assertSame('cli:test', $this->store->auditLog()[0]['actor']);
    }

    #[Test]
    public function theValidatedClampedValueIsStored(): void
    {
        $this->updater()->set('chat.days', '5000', 'cli:test');

        self::assertSame('100', $this->store->read($this->registry->get('chat.days')->id)?->current);
    }

    #[Test]
    public function anInvalidValueIsRefusedAndNothingIsWritten(): void
    {
        foreach (['0', 'abc', str_repeat('9', 70000)] as $value) {
            try {
                $this->updater()->set('chat.days', $value, 'cli:test');
                self::fail('Expected InvalidRuntimeSettingValue.');
            } catch (InvalidRuntimeSettingValue $e) {
                self::assertStringStartsWith('Invalid value for runtime setting "chat.days"', $e->getMessage());
            }
        }

        self::assertSame([], $this->store->auditLog());
        self::assertSame([], $this->dispatcher->events);
    }

    #[Test]
    public function unknownSettingsAreRefused(): void
    {
        $this->expectException(UnknownRuntimeSetting::class);

        $this->updater()->set('chat.unknown', '1', 'cli:test');
    }

    #[Test]
    public function envSourceSettingsAreRefused(): void
    {
        $this->expectException(RuntimeSettingNotWritable::class);
        $this->expectExceptionMessage('"chat.mode" uses "source: env"');

        $this->updater()->set('chat.mode', 'x', 'cli:test');
    }

    #[Test]
    public function prepareReturnsTheStoredForm(): void
    {
        self::assertSame('100', $this->updater()->prepare('chat.days', '101'));
    }

    #[Test]
    public function dryRunsWriteNothing(): void
    {
        $updater = $this->updater();

        $set    = $updater->set('chat.days', '7', 'cli:test', dryRun: true);
        $unset  = $updater->unset('chat.days', 'cli:test', dryRun: true);
        $rotate = $updater->rotate('API_TOKEN', 'REDACTED-TEST-A', new DateInterval('PT1H'), 'cli:test', dryRun: true);

        self::assertTrue($set->dryRun);
        self::assertNotNull($set->newFingerprint);
        self::assertNull($set->version);
        self::assertNull($unset->newFingerprint);
        self::assertSame(RuntimeSettingAction::Rotate, $rotate->action);
        self::assertSame([], $this->store->auditLog());
        self::assertSame([], $this->dispatcher->events);
    }

    #[Test]
    public function unsetFallsBackToTheConfiguredValue(): void
    {
        $definition = $this->registry->get('chat.days');
        $this->updater()->set('chat.days', '45', 'cli:test');
        $change = $this->updater()->unset('chat.days', 'cli:test');

        self::assertNull($change->newFingerprint);
        self::assertSame(30, $this->resolver->get($definition));
    }

    #[Test]
    public function rotateKeepsThePreviousValueForTheWindow(): void
    {
        $definition = $this->registry->get('API_TOKEN');
        $updater    = $this->updater();
        $updater->set('API_TOKEN', 'REDACTED-TEST-A', 'cli:test');

        $updater->rotate('API_TOKEN', 'REDACTED-TEST-B', new DateInterval('PT1H'), 'cli:test');

        self::assertSame(['REDACTED-TEST-B', 'REDACTED-TEST-A'], $this->resolver->candidates($definition));
        $this->clock->advance('+1 hour');
        $this->resolver->clearRuntimeCache();
        self::assertSame(['REDACTED-TEST-B'], $this->resolver->candidates($definition));

        // The expired previous value is purged on the next write.
        $updater->set('API_TOKEN', 'REDACTED-TEST-C', 'cli:test');
        self::assertNull($this->store->read($definition->id)?->previous);
    }

    #[Test]
    public function rotationWindowIsBounded(): void
    {
        foreach (['P31D', 'invert'] as $case) {
            $interval = new DateInterval($case === 'invert' ? 'PT1H' : $case);
            if ($case === 'invert') {
                $interval->invert = 1;
            }
            try {
                $this->updater()->rotate('API_TOKEN', 'REDACTED-TEST-A', $interval, 'cli:test');
                self::fail('Expected RuntimeSettingConfigurationError.');
            } catch (RuntimeSettingConfigurationError $e) {
                self::assertStringContainsString('between 0 seconds and 30 days', $e->getMessage());
            }
        }
        self::assertSame([], $this->store->auditLog());
    }

    #[Test]
    public function aMalformedActorIsRefused(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);

        $this->updater()->unset('chat.days', 'nobody');
    }

    #[Test]
    public function aFailedWriteForgetsTheNamespaceAndPublishesNothing(): void
    {
        $store      = new PdoRuntimeSettingStore('sqlite::memory:');
        $resolver   = new RuntimeSettingResolver($store, null, $this->clock, 300);
        $updater    = new RuntimeSettingUpdater($this->registry, $store, $resolver, $this->fingerprinter(), $this->dispatcher, $this->clock);
        $definition = $this->registry->get('chat.days');

        $updater->set('chat.days', '45', 'cli:test');
        $this->dispatcher->clear();
        self::assertSame(45, $resolver->get($definition));

        $store->failBeforeCommit = true;
        try {
            $updater->set('chat.days', '46', 'cli:test');
            self::fail('Expected the simulated failure.');
        } catch (RuntimeException) {
        }

        self::assertSame([], $this->dispatcher->events, 'no event after a rollback');
        self::assertSame(1, $store->auditCount(), 'the audit entry was rolled back with the value');
        self::assertSame(45, $resolver->get($definition), 'the memo was dropped and the committed value re-read');
    }

    #[Test]
    public function aWriteInsideTheCallersTransactionIsRefused(): void
    {
        $store   = new PdoRuntimeSettingStore('sqlite::memory:');
        $updater = new RuntimeSettingUpdater($this->registry, $store, new RuntimeSettingResolver($store), $this->fingerprinter(), $this->dispatcher);
        $store->connection()->beginTransaction();

        try {
            $updater->set('chat.days', '45', 'cli:test');
            self::fail('Expected RuntimeSettingNotWritable.');
        } catch (RuntimeSettingNotWritable $e) {
            self::assertStringContainsString('own transaction', $e->getMessage());
        }
        self::assertSame([], $this->dispatcher->events);
    }

    #[Test]
    public function aFailingListenerIsLoggedNotRethrown(): void
    {
        $logger  = new RecordingLogger();
        $updater = new RuntimeSettingUpdater($this->registry, $this->store, $this->resolver, $this->fingerprinter(), new RecordingDispatcher(new RuntimeException('listener')), $this->clock, $logger);

        $change = $updater->set('chat.days', '45', 'cli:test');

        self::assertSame(RuntimeSettingAction::Set, $change->action);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('chat.days', $logger->records[0]['context']['id']);
    }

    #[Test]
    public function worksWithoutDispatcherClockOrLogger(): void
    {
        $updater = new RuntimeSettingUpdater($this->registry, $this->store, $this->resolver, $this->fingerprinter());

        self::assertSame(RuntimeSettingAction::Rotate, $updater->rotate('API_TOKEN', 'REDACTED-TEST-A', new DateInterval('PT0S'), 'cli:test')->action);
    }

    private function updater(): RuntimeSettingUpdater
    {
        return new RuntimeSettingUpdater($this->registry, $this->store, $this->resolver, $this->fingerprinter(), $this->dispatcher, $this->clock);
    }

    private function fingerprinter(): RuntimeValueFingerprinter
    {
        return new RuntimeValueFingerprinter(str_repeat('k', 32));
    }
}
