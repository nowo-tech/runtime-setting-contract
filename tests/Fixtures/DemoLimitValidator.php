<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Tests\Fixtures;

use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingValidatorInterface;

use function is_int;
use function min;

/**
 * A bundle's validator: refuses values below 1, clamps above 100.
 */
final class DemoLimitValidator implements RuntimeSettingValidatorInterface
{
    public function validate(string|int|float|bool $value): int
    {
        if (!is_int($value) || $value < 1) {
            throw InvalidRuntimeSettingValue::because('must be at least 1');
        }

        return min($value, 100);
    }
}
