<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Unit\Contract;

use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

use const INF;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

final class RuntimeSettingTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{RuntimeSettingType, string, bool|float|int|string}>
     */
    public static function valid(): iterable
    {
        yield 'string' => [RuntimeSettingType::String, 'héllo', 'héllo'];
        yield 'empty string' => [RuntimeSettingType::String, '', ''];
        yield 'int' => [RuntimeSettingType::Int, '42', 42];
        yield 'zero' => [RuntimeSettingType::Int, '0', 0];
        yield 'negative int' => [RuntimeSettingType::Int, '-7', -7];
        yield 'int max' => [RuntimeSettingType::Int, (string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'int min' => [RuntimeSettingType::Int, (string) PHP_INT_MIN, PHP_INT_MIN];
        yield 'float' => [RuntimeSettingType::Float, '1.5', 1.5];
        yield 'float exp' => [RuntimeSettingType::Float, '2e3', 2000.0];
        yield 'float int form' => [RuntimeSettingType::Float, '-3', -3.0];
        yield 'bool true' => [RuntimeSettingType::Bool, 'true', true];
        yield 'bool 1' => [RuntimeSettingType::Bool, '1', true];
        yield 'bool false' => [RuntimeSettingType::Bool, 'false', false];
        yield 'bool 0' => [RuntimeSettingType::Bool, '0', false];
    }

    #[Test]
    #[DataProvider('valid')]
    public function parsesValidValues(RuntimeSettingType $type, string $raw, string|int|float|bool $expected): void
    {
        self::assertSame($expected, $type->parse($raw));
    }

    /**
     * @return iterable<string, array{RuntimeSettingType, string}>
     */
    public static function invalid(): iterable
    {
        yield 'bad utf8' => [RuntimeSettingType::String, "\xff\xfe"];
        yield 'int overflow saturates never' => [RuntimeSettingType::Int, '99999999999999999999'];
        yield 'int just over max' => [RuntimeSettingType::Int, '9223372036854775808'];
        yield 'int leading zero' => [RuntimeSettingType::Int, '007'];
        yield 'int plus' => [RuntimeSettingType::Int, '+5'];
        yield 'int spaces' => [RuntimeSettingType::Int, ' 5'];
        yield 'int newline' => [RuntimeSettingType::Int, "5\n"];
        yield 'int float' => [RuntimeSettingType::Int, '5.0'];
        yield 'float text' => [RuntimeSettingType::Float, 'abc'];
        yield 'float inf' => [RuntimeSettingType::Float, '1e999'];
        yield 'float too long' => [RuntimeSettingType::Float, '1.' . str_repeat('1', 70)];
        yield 'float nan' => [RuntimeSettingType::Float, 'NAN'];
        yield 'bool yes' => [RuntimeSettingType::Bool, 'yes'];
        yield 'bool TRUE' => [RuntimeSettingType::Bool, 'TRUE'];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function refusesInvalidValues(RuntimeSettingType $type, string $raw): void
    {
        $this->expectException(InvalidRuntimeSettingValue::class);

        $type->parse($raw);
    }

    #[Test]
    public function refusesValuesLongerThanTheBoundBeforeParsing(): void
    {
        $this->expectException(InvalidRuntimeSettingValue::class);
        $this->expectExceptionMessage('longer than 4 bytes');

        RuntimeSettingType::String->parse('12345', 4);
    }

    #[Test]
    public function formatRoundTrips(): void
    {
        self::assertSame('true', RuntimeSettingType::Bool->format(true));
        self::assertSame('false', RuntimeSettingType::Bool->format(false));
        self::assertSame('12', RuntimeSettingType::Int->format(12));
        self::assertSame('x', RuntimeSettingType::String->format('x'));
        self::assertSame('0.1', RuntimeSettingType::Float->format(0.1));
        self::assertSame('1.0', RuntimeSettingType::Float->format(1.0));
        self::assertSame('1.0e25', RuntimeSettingType::Float->format(1.0e25));
        self::assertSame('1.0e-25', RuntimeSettingType::Float->format(1.0e-25));
        self::assertSame(1.0e25, RuntimeSettingType::Float->parse(RuntimeSettingType::Float->format(1.0e25)));
        self::assertSame(1.0e-25, RuntimeSettingType::Float->parse(RuntimeSettingType::Float->format(1.0e-25)));
    }

    #[Test]
    public function formatRefusesAnotherType(): void
    {
        $this->expectException(InvalidRuntimeSettingValue::class);

        RuntimeSettingType::Int->format('12');
    }

    #[Test]
    public function formatRefusesInfiniteFloats(): void
    {
        $this->expectException(InvalidRuntimeSettingValue::class);

        RuntimeSettingType::Float->format(INF);
    }

    #[Test]
    public function normalizesConfiguredValues(): void
    {
        self::assertSame(30, RuntimeSettingType::Int->normalize('30'));
        self::assertSame(30, RuntimeSettingType::Int->normalize(30));
        self::assertSame(3.0, RuntimeSettingType::Float->normalize(3));
        self::assertSame(2.5, RuntimeSettingType::Float->normalize(2.5));
        self::assertTrue(RuntimeSettingType::Bool->normalize(true));
        self::assertSame('a', RuntimeSettingType::String->normalize('a'));
    }

    #[Test]
    public function normalizeRefusesOtherTypes(): void
    {
        $this->expectException(InvalidRuntimeSettingValue::class);

        RuntimeSettingType::Bool->normalize(1);
    }

    #[Test]
    public function normalizeAppliesTheLengthBound(): void
    {
        $this->expectException(InvalidRuntimeSettingValue::class);

        RuntimeSettingType::Int->normalize(123456, 3);
    }
}
