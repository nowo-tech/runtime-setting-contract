<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Unit\Contract;

use DateTimeImmutable;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingSourceFailure;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingAction;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingChange;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingWriteContext;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueCacheEntry;
use Nowo\RuntimeSettingContract\Contract\RuntimeValueFingerprinter;
use Nowo\RuntimeSettingContract\Contract\SettingChangedEvent;
use Nowo\RuntimeSettingContract\Contract\StoredRuntimeValue;
use Nowo\RuntimeSettingContract\Contract\SystemClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function get_object_vars;
use function str_repeat;
use function strlen;

final class ValueObjectsTest extends TestCase
{
    #[Test]
    public function storedValueExposesThePreviousValueOnlyInsideItsWindow(): void
    {
        $expires = new DateTimeImmutable('2026-11-16 13:00:00');
        $stored  = new StoredRuntimeValue('new', 'old', $expires);

        self::assertSame('old', $stored->previousAt(new DateTimeImmutable('2026-11-16 12:59:59')));
        self::assertNull($stored->previousAt($expires));
        self::assertNull((new StoredRuntimeValue('only'))->previousAt($expires));
    }

    #[Test]
    public function storedValueNeedsBothPreviousAndExpiry(): void
    {
        $this->expectException(RuntimeSettingSourceFailure::class);

        new StoredRuntimeValue('new', 'old');
    }

    #[Test]
    public function fingerprintsAreKeyedAndPerSetting(): void
    {
        $a  = new RuntimeValueFingerprinter(str_repeat('k', 16));
        $b  = new RuntimeValueFingerprinter(str_repeat('j', 16));
        $id = RuntimeSettingId::fromString('a.b');

        self::assertSame(16, strlen($a->fingerprint($id, 'v')));
        self::assertSame($a->fingerprint($id, 'v'), $a->fingerprint($id, 'v'));
        self::assertNotSame($a->fingerprint($id, 'v'), $b->fingerprint($id, 'v'));
        self::assertNotSame($a->fingerprint($id, 'v'), $a->fingerprint(RuntimeSettingId::fromString('a.c'), 'v'));
    }

    #[Test]
    public function fingerprintKeyMustBeLongEnough(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);

        new RuntimeValueFingerprinter('short');
    }

    #[Test]
    public function writeContextValidatesTheActor(): void
    {
        $fingerprinter = new RuntimeValueFingerprinter(str_repeat('k', 16));

        self::assertSame('cli:hector', (new RuntimeSettingWriteContext('cli:hector', $fingerprinter))->actor);
        self::assertSame('user:42', (new RuntimeSettingWriteContext('user:42', $fingerprinter))->actor);

        $this->expectException(RuntimeSettingConfigurationError::class);
        new RuntimeSettingWriteContext("cli:a b\n", $fingerprinter);
    }

    #[Test]
    public function changeAndEventCarryIdsOnly(): void
    {
        $id     = RuntimeSettingId::fromString('a.b');
        $change = new RuntimeSettingChange($id, RuntimeSettingAction::Set, 'old', 'new', '2-abc');
        $event  = SettingChangedEvent::fromChange($change);

        self::assertTrue($change->changed());
        self::assertFalse((new RuntimeSettingChange($id, RuntimeSettingAction::Set, 'same', 'same', '3'))->changed());
        self::assertTrue((new RuntimeSettingChange($id, RuntimeSettingAction::Rotate, 'same', 'same', '3'))->changed());
        self::assertFalse($change->dryRun);
        self::assertSame(['namespace' => 'a', 'key' => 'b', 'action' => RuntimeSettingAction::Set], get_object_vars($event));
        self::assertTrue($event->id()->equals($id));
    }

    #[Test]
    public function cacheEntryAndClock(): void
    {
        self::assertNull((new RuntimeValueCacheEntry(null))->value);
        self::assertLessThanOrEqual(2, abs((new SystemClock())->now()->getTimestamp() - time()));
    }
}
