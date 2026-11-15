<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Unit\Contract;

use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\Exception\UnknownRuntimeSetting;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingRegistry;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function str_repeat;

final class RuntimeSettingRegistryTest extends TestCase
{
    #[Test]
    public function listsSortedAndByNamespace(): void
    {
        $registry = new RuntimeSettingRegistry([
            new RuntimeSettingDefinition('mail.from', RuntimeSettingType::String),
            new RuntimeSettingDefinition('chat.days', RuntimeSettingType::Int),
            new RuntimeSettingDefinition('API_TOKEN', RuntimeSettingType::String),
        ]);

        self::assertSame(['API_TOKEN', 'chat.days', 'mail.from'], array_map(static fn (RuntimeSettingDefinition $d): string => $d->idString(), $registry->all()));
        self::assertSame(['chat.days'], array_map(static fn (RuntimeSettingDefinition $d): string => $d->idString(), $registry->all('chat')));
        self::assertSame(['API_TOKEN'], array_map(static fn (RuntimeSettingDefinition $d): string => $d->idString(), $registry->all('env')));
        self::assertTrue($registry->has('chat.days'));
        self::assertFalse($registry->has('chat.Days'));
        self::assertFalse($registry->has(str_repeat('a', 300)));
        self::assertSame('chat.days', $registry->get('chat.days')->idString());
    }

    #[Test]
    public function refusesDuplicates(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('"chat.days" is registered twice');

        new RuntimeSettingRegistry([
            new RuntimeSettingDefinition('chat.days', RuntimeSettingType::Int),
            new RuntimeSettingDefinition('chat.days', RuntimeSettingType::Int),
        ]);
    }

    #[Test]
    public function unknownIdsAreRefused(): void
    {
        $this->expectException(UnknownRuntimeSetting::class);
        $this->expectExceptionMessage('Unknown runtime setting "chat.other"');

        (new RuntimeSettingRegistry([]))->get('chat.other');
    }

    #[Test]
    public function malformedIdsAreRefusedWithoutEcho(): void
    {
        $this->expectException(InvalidRuntimeSettingId::class);

        (new RuntimeSettingRegistry([]))->get("chat.days\n<script>");
    }
}
