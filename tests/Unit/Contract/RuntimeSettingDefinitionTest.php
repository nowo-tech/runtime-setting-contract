<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Unit\Contract;

use LogicException;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingErrorPolicy;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingSource;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\Validator\Unbounded;
use Nowo\RuntimeSettingContract\Tests\Fixtures\DemoLimitValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RuntimeSettingDefinitionTest extends TestCase
{
    #[Test]
    public function defaultsAreEnvAndFailClosed(): void
    {
        $definition = new RuntimeSettingDefinition('chat.retention_days', RuntimeSettingType::Int);

        self::assertSame(RuntimeSettingSource::Env, $definition->source);
        self::assertSame(RuntimeSettingErrorPolicy::FailClosed, $definition->onError);
        self::assertFalse($definition->secret);
        self::assertFalse($definition->required);
        self::assertFalse($definition->isWritable());
        self::assertSame('chat', $definition->namespace());
        self::assertSame('retention_days', $definition->key());
        self::assertSame('chat.retention_days', $definition->idString());
        self::assertSame(RuntimeSettingType::MAX_LENGTH, $definition->maxLength);
        self::assertNull($definition->configuredValue());
        self::assertNull($definition->defaultValue());
    }

    #[Test]
    public function acceptsAnIdObjectAndNormalizesConfiguredValues(): void
    {
        $definition = new RuntimeSettingDefinition(
            RuntimeSettingId::fromString('CHAT_DAYS'),
            RuntimeSettingType::Int,
            RuntimeSettingSource::DbThenEnv,
            validator: new Unbounded(),
            configured: '30',
            default: 90,
        );

        self::assertTrue($definition->isWritable());
        self::assertSame(30, $definition->configuredValue());
        self::assertSame(90, $definition->defaultValue());
    }

    #[Test]
    public function anEmptyEnvValueIsNotConfiguredForNonStringTypes(): void
    {
        self::assertNull((new RuntimeSettingDefinition('a.b', RuntimeSettingType::Int, configured: ''))->configuredValue());
        self::assertSame('', (new RuntimeSettingDefinition('a.b', RuntimeSettingType::String, configured: ''))->configuredValue());
    }

    #[Test]
    public function refusesAConfiguredValueOfAnotherType(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);
        $this->expectExceptionMessage('configured value of runtime setting "a.b" is invalid');

        new RuntimeSettingDefinition('a.b', RuntimeSettingType::Int, configured: 'abc');
    }

    #[Test]
    public function refusesAnInvalidDefault(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);

        new RuntimeSettingDefinition('a.b', RuntimeSettingType::Bool, default: 'maybe');
    }

    #[Test]
    public function refusesAnOutOfRangeMaxLength(): void
    {
        $this->expectException(RuntimeSettingConfigurationError::class);

        new RuntimeSettingDefinition('a.b', RuntimeSettingType::String, maxLength: 0);
    }

    #[Test]
    public function runsAValidatorInterface(): void
    {
        $definition = new RuntimeSettingDefinition('a.b', RuntimeSettingType::Int, validator: new DemoLimitValidator());

        self::assertSame(100, $definition->parseAndValidate('500'));
        self::assertSame(5, $definition->validate(5));

        $this->expectException(InvalidRuntimeSettingValue::class);
        $definition->parseAndValidate('0');
    }

    #[Test]
    public function runsACallableValidator(): void
    {
        $definition = new RuntimeSettingDefinition('a.b', RuntimeSettingType::Int, validator: [new DemoLimitValidator(), 'validate']);

        self::assertSame(100, $definition->validate(101));
    }

    #[Test]
    public function aValidatorThatThrowsSomethingElseRefusesTheValue(): void
    {
        $definition = new RuntimeSettingDefinition('a.b', RuntimeSettingType::String, validator: static function (): never {
            throw new LogicException('boom');
        });

        $this->expectException(InvalidRuntimeSettingValue::class);
        $this->expectExceptionMessage('refused by the validator');

        $definition->validate('x');
    }

    #[Test]
    public function aValidatorCannotChangeTheType(): void
    {
        $definition = new RuntimeSettingDefinition('a.b', RuntimeSettingType::Int, validator: static fn (string|int|float|bool $v): string => 'x');

        $this->expectException(InvalidRuntimeSettingValue::class);

        $definition->validate(1);
    }

    #[Test]
    public function aValidatorCannotExceedTheLengthBound(): void
    {
        $definition = new RuntimeSettingDefinition('a.b', RuntimeSettingType::String, validator: static fn (string|int|float|bool $v): string => 'toolong', maxLength: 3);

        $this->expectException(InvalidRuntimeSettingValue::class);

        $definition->validate('ok');
    }

    #[Test]
    public function aSecretCannotBeEmpty(): void
    {
        $definition = new RuntimeSettingDefinition('a.b', RuntimeSettingType::String, secret: true);

        $this->expectException(InvalidRuntimeSettingValue::class);

        $definition->parseAndValidate('');
    }
}
