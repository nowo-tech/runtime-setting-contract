<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;

/**
 * The bundle's validation and clamping for one setting. It runs on every value, whatever its source: the
 * configured (env) value, the stored value on every read, and the new value before every write.
 *
 * Return the value to use (possibly clamped to the bundle's bounds, of the same type), or throw
 * {@see InvalidRuntimeSettingValue} with a reason that does not quote the value. Keep it pure: no I/O, no state.
 */
interface RuntimeSettingValidatorInterface
{
    /**
     * @throws InvalidRuntimeSettingValue
     */
    public function validate(string|int|float|bool $value): string|int|float|bool;
}
