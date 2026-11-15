<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Unit\Bridge\Psr6;

use DateTimeImmutable;
use Nowo\RuntimeSettingContract\Bridge\Psr6\Psr6RuntimeValueCache;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\StoredRuntimeValue;
use Nowo\RuntimeSettingContract\Tests\Support\RecordingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function hash;
use function str_repeat;

final class Psr6RuntimeValueCacheTest extends TestCase
{
    #[Test]
    public function roundTripsUnderTheSameVersionOnly(): void
    {
        $cache   = new Psr6RuntimeValueCache(new ArrayAdapter());
        $id      = RuntimeSettingId::fromString('a.b');
        $expires = new DateTimeImmutable('@1900000000');

        self::assertNull($cache->get($id, 'v1'));

        $cache->set($id, 'v1', new StoredRuntimeValue('new', 'old', $expires));
        $entry = $cache->get($id, 'v1');
        self::assertNotNull($entry);
        $stored = $entry->value;
        self::assertNotNull($stored);
        self::assertSame('new', $stored->current);
        self::assertSame('old', $stored->previous);
        self::assertEquals($expires, $stored->previousExpiresAt);
        self::assertNull($cache->get($id, 'v2'), 'another version token is a miss');

        $cache->set($id, 'v2', new StoredRuntimeValue('plain'));
        self::assertNull($cache->get($id, 'v2')?->value?->previous);

        $cache->set($id, 'v3', null);
        $absent = $cache->get($id, 'v3');
        self::assertNotNull($absent);
        self::assertNull($absent->value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformed(): iterable
    {
        yield 'not an array' => ['x'];
        yield 'missing keys' => [['v' => 'v1']];
        yield 'extra key' => [['v' => 'v1', 'c' => 'a', 'p' => null, 'e' => null, 'z' => 1]];
        yield 'version not string' => [['v' => 1, 'c' => 'a', 'p' => null, 'e' => null]];
        yield 'version too long' => [['v' => str_repeat('v', 129), 'c' => 'a', 'p' => null, 'e' => null]];
        yield 'current not string' => [['v' => 'v1', 'c' => 5, 'p' => null, 'e' => null]];
        yield 'current too long' => [['v' => 'v1', 'c' => str_repeat('c', 65537), 'p' => null, 'e' => null]];
        yield 'absent with previous' => [['v' => 'v1', 'c' => null, 'p' => 'x', 'e' => null]];
        yield 'previous without expiry' => [['v' => 'v1', 'c' => 'a', 'p' => 'x', 'e' => null]];
        yield 'expiry not int' => [['v' => 'v1', 'c' => 'a', 'p' => 'x', 'e' => 'soon']];
        yield 'previous too long' => [['v' => 'v1', 'c' => 'a', 'p' => str_repeat('p', 65537), 'e' => 1]];
    }

    #[Test]
    #[DataProvider('malformed')]
    public function aMalformedEntryIsAMiss(mixed $payload): void
    {
        $pool = new ArrayAdapter();
        $id   = RuntimeSettingId::fromString('a.b');
        $item = $pool->getItem('nowo_runtime_setting.' . hash('sha256', 'a.b'));
        $item->set($payload);
        $pool->save($item);

        self::assertNull((new Psr6RuntimeValueCache($pool))->get($id, 'v1'));
    }

    #[Test]
    public function poolFailuresAreMissesAndLoggedWithoutValues(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willThrowException(new RuntimeException('redis down'));
        $logger = new RecordingLogger();
        $cache  = new Psr6RuntimeValueCache($pool, 60, $logger);
        $id     = RuntimeSettingId::fromString('a.b');

        self::assertNull($cache->get($id, 'v1'));
        $cache->set($id, 'v1', new StoredRuntimeValue('REDACTED-TEST'));

        self::assertCount(2, $logger->records);
        self::assertStringNotContainsString('REDACTED-TEST', $logger->text());
    }
}
