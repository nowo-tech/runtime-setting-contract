<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Unit\Contract;

use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class RuntimeSettingIdTest extends TestCase
{
    #[Test]
    public function parsesNamespaceAndKey(): void
    {
        $id = RuntimeSettingId::fromString('chat.retention_days');

        self::assertSame('chat', $id->namespace);
        self::assertSame('retention_days', $id->key);
        self::assertFalse($id->isEnvName());
        self::assertSame('chat.retention_days', $id->toString());
        self::assertSame('chat.retention_days', (string) $id);
    }

    #[Test]
    public function keysMayHaveDots(): void
    {
        $id = RuntimeSettingId::fromString('mail.smtp.password');

        self::assertSame('mail', $id->namespace);
        self::assertSame('smtp.password', $id->key);
    }

    #[Test]
    public function environmentVariableNamesLiveInTheEnvNamespace(): void
    {
        $id = RuntimeSettingId::fromString('CHAT_RETENTION_DAYS');

        self::assertSame(RuntimeSettingId::ENV_NAMESPACE, $id->namespace);
        self::assertSame('CHAT_RETENTION_DAYS', $id->key);
        self::assertTrue($id->isEnvName());
        self::assertSame('CHAT_RETENTION_DAYS', $id->toString());
        self::assertTrue($id->equals(RuntimeSettingId::of('env', 'CHAT_RETENTION_DAYS')));
        self::assertFalse($id->equals(RuntimeSettingId::fromString('chat.retention_days')));
        self::assertFalse(RuntimeSettingId::of('a', 'b')->equals(RuntimeSettingId::of('a', 'c')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => ['a.' . str_repeat('b', 200)];
        yield 'uppercase namespace' => ['Chat.days'];
        yield 'uppercase key' => ['chat.Days'];
        yield 'trailing newline' => ["chat.days\n"];
        yield 'env name trailing newline' => ["CHAT_DAYS\n"];
        yield 'leading space' => [' chat.days'];
        yield 'nbsp' => ["chat.da\u{00A0}ys"];
        yield 'empty key' => ['chat.'];
        yield 'empty namespace' => ['.days'];
        yield 'double dot' => ['chat..days'];
        yield 'reserved env namespace' => ['env.days'];
        yield 'env name lowercase' => ['Chat_days'];
        yield 'env name digit first' => ['1CHAT'];
        yield 'namespace too long' => [str_repeat('a', 65) . '.k'];
        yield 'key too long' => ['a.' . str_repeat('k', 129)];
    }

    #[Test]
    #[DataProvider('invalidIds')]
    public function refusesMalformedIdsWithoutEchoingThem(string $id): void
    {
        try {
            RuntimeSettingId::fromString($id);
            self::fail('Expected InvalidRuntimeSettingId.');
        } catch (InvalidRuntimeSettingId $e) {
            self::assertStringNotContainsString($id === '' ? 'x-never' : $id, $e->getMessage());
        }
    }

    #[Test]
    public function ofValidatesTheEnvNamespaceKey(): void
    {
        $this->expectException(InvalidRuntimeSettingId::class);

        RuntimeSettingId::of('env', 'lower');
    }
}
