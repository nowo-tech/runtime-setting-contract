<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Unit\InMemory;

use Nowo\RuntimeSettingContract\Contract\RuntimeSettingAction;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriteContext;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\Contract\Validator\Unbounded;
use Nowo\RuntimeSettingContract\InMemory\InMemoryRuntimeSettingStore;
use Nowo\RuntimeSettingContract\Tests\Support\MutableClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function str_repeat;

final class InMemoryRuntimeSettingStoreTest extends TestCase
{
    #[Test]
    public function versionsChangeOnEveryWriteAndAreNeverReused(): void
    {
        $store      = new InMemoryRuntimeSettingStore();
        $definition = new RuntimeSettingDefinition('a.b', RuntimeSettingType::String, RuntimeSettingSource::Db, validator: new Unbounded());
        $context    = $this->context();

        self::assertSame(InMemoryRuntimeSettingStore::INITIAL_VERSION, $store->namespaceVersion('a'));
        $seen = [];
        for ($i = 0; $i < 50; ++$i) {
            $change = $i % 2 === 0 ? $store->set($definition, 'v' . $i, $context) : $store->unset($definition, $context);
            self::assertNotContains($change->version, $seen);
            self::assertSame($change->version, $store->namespaceVersion('a'));
            $seen[] = $change->version;
        }
        self::assertSame(InMemoryRuntimeSettingStore::INITIAL_VERSION, $store->namespaceVersion('other'));
    }

    #[Test]
    public function rotateWithoutACurrentValueStoresNoPrevious(): void
    {
        $clock      = new MutableClock();
        $store      = new InMemoryRuntimeSettingStore($clock);
        $definition = new RuntimeSettingDefinition('a.b', RuntimeSettingType::String, RuntimeSettingSource::Db, validator: new Unbounded());

        $change = $store->rotate($definition, 'REDACTED-TEST-NEW', $clock->now()->modify('+1 hour'), $this->context());

        self::assertSame(RuntimeSettingAction::Rotate, $change->action);
        self::assertNull($store->read($definition->id)?->previous);
    }

    #[Test]
    public function anExpiredPreviousValueIsPurgedOnTheNextWrite(): void
    {
        $clock = new MutableClock();
        $store = new InMemoryRuntimeSettingStore($clock);
        $a     = new RuntimeSettingDefinition('ns.a', RuntimeSettingType::String, RuntimeSettingSource::Db, validator: new Unbounded());
        $b     = new RuntimeSettingDefinition('ns.b', RuntimeSettingType::String, RuntimeSettingSource::Db, validator: new Unbounded());
        $store->set($a, 'old', $this->context());
        $store->rotate($a, 'new', $clock->now()->modify('+1 minute'), $this->context());

        $store->set($b, 'x', $this->context());
        self::assertSame('old', $store->read($a->id)?->previous, 'still inside the window');

        $clock->advance('+1 minute');
        $store->set($b, 'y', $this->context());
        self::assertNull($store->read($a->id)?->previous);
        self::assertSame('new', $store->read($a->id)?->current);
    }

    #[Test]
    public function theAuditTrailHoldsFingerprintsNotValuesAndIsBounded(): void
    {
        $store      = new InMemoryRuntimeSettingStore();
        $definition = new RuntimeSettingDefinition('a.b', RuntimeSettingType::String, RuntimeSettingSource::Db, secret: true, validator: new Unbounded());

        for ($i = 0; $i < InMemoryRuntimeSettingStore::MAX_AUDIT_ENTRIES + 10; ++$i) {
            $store->set($definition, 'REDACTED-TEST-' . $i, $this->context());
        }

        $audit = $store->auditLog();
        self::assertCount(InMemoryRuntimeSettingStore::MAX_AUDIT_ENTRIES, $audit);
        self::assertStringNotContainsString('REDACTED-TEST', (string) json_encode($audit));
        self::assertSame('set', $audit[0]['action']);
        self::assertSame('a.b', $audit[0]['id']);
    }

    private function context(): RuntimeSettingWriteContext
    {
        return new RuntimeSettingWriteContext('cli:test', new RuntimeValueFingerprinter(str_repeat('k', 16)));
    }
}
