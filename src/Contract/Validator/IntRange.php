<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract\Validator;

use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingValidatorInterface;

use function is_int;
use function max;
use function min;
use function sprintf;

/**
 * Bounds an integer setting on both sides. By default a value outside the range is refused; with `clamp: true` it is
 * lowered or raised to the nearest bound instead.
 */
final readonly class IntRange implements RuntimeSettingValidatorInterface
{
    /**
     * @throws RuntimeSettingConfigurationError when min is greater than max
     */
    public function __construct(
        public int $min,
        public int $max,
        public bool $clamp = false,
    ) {
        if ($min > $max) {
            throw RuntimeSettingConfigurationError::because('IntRange: min must not be greater than max.');
        }
    }

    public function validate(string|int|float|bool $value): int
    {
        if (!is_int($value)) {
            throw InvalidRuntimeSettingValue::because('expected an integer');
        }

        if ($value >= $this->min && $value <= $this->max) {
            return $value;
        }

        if ($this->clamp) {
            return max($this->min, min($this->max, $value));
        }

        throw InvalidRuntimeSettingValue::because(sprintf('must be between %d and %d', $this->min, $this->max));
    }
}
