<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract\Validator;

use Nowo\RuntimeSettingContract\Contract\RuntimeSettingValidatorInterface;

/**
 * Explicit opt-out: the setting accepts any value of its type within the type's bounds (64 KiB, the platform integer
 * range, finite floats, valid UTF-8).
 *
 * Use it only for values that are truly free-form and that nothing downstream turns into a deletion, a duration, a
 * header or a DSN. It exists so that the choice is visible in code review (audit QS-M-2).
 */
final readonly class Unbounded implements RuntimeSettingValidatorInterface
{
    public function validate(string|int|float|bool $value): string|int|float|bool
    {
        return $value;
    }
}
