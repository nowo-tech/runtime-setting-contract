<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;

use function filter_var;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;
use function sprintf;
use function strlen;
use function var_export;

use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_INT;

/**
 * Scalar type of a setting. Sources store every value as a string; this enum parses and formats it strictly.
 *
 * Parsing is bounded and fails closed: the length is checked before any pattern, an int outside the platform range
 * is refused (never saturated), a float must be finite, a string must be valid UTF-8.
 */
enum RuntimeSettingType: string
{
    /** Hard upper bound for any stored value, in bytes. A definition may set a lower one. */
    public const MAX_LENGTH = 65536;

    case String = 'string';

    case Int = 'int';

    case Float = 'float';

    case Bool = 'bool';

    /**
     * @throws InvalidRuntimeSettingValue
     */
    public function parse(string $raw, int $maxLength = self::MAX_LENGTH): string|int|float|bool
    {
        if (strlen($raw) > $maxLength) {
            throw InvalidRuntimeSettingValue::because(sprintf('longer than %d bytes', $maxLength));
        }

        switch ($this) {
            case self::String:
                if (preg_match('//u', $raw) !== 1) {
                    throw InvalidRuntimeSettingValue::because('not valid UTF-8');
                }

                return $raw;
            case self::Int:
                $int = preg_match('/^-?(?:0|[1-9][0-9]{0,18})$/D', $raw) === 1
                    ? filter_var($raw, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE)
                    : null;
                if (!is_int($int)) {
                    throw InvalidRuntimeSettingValue::because('not an integer in range');
                }

                return $int;
            case self::Float:
                if (strlen($raw) > 64 || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][-+]?[0-9]{1,3})?$/D', $raw) !== 1) {
                    throw InvalidRuntimeSettingValue::because('not a decimal number');
                }
                $float = (float) $raw;
                if (!is_finite($float)) {
                    throw InvalidRuntimeSettingValue::because('not a finite number');
                }

                return $float;
            case self::Bool:
                return match ($raw) {
                    'true', '1'  => true,
                    'false', '0' => false,
                    default      => throw InvalidRuntimeSettingValue::because('not a boolean (true, false, 1 or 0)'),
                };
        }
    }

    /**
     * Converts a configured value (from YAML or `%env()%`) to this type. Strings go through {@see parse()}.
     *
     * @throws InvalidRuntimeSettingValue
     */
    public function normalize(mixed $value, int $maxLength = self::MAX_LENGTH): string|int|float|bool
    {
        if (is_string($value)) {
            return $this->parse($value, $maxLength);
        }

        $normalized = match (true) {
            $this === self::Int && is_int($value),
            $this === self::Bool && is_bool($value)                        => $value,
            $this === self::Float && is_int($value)                        => (float) $value,
            $this === self::Float && is_float($value) && is_finite($value) => $value,
            default                                                        => throw InvalidRuntimeSettingValue::because(sprintf('expected a value of type %s', $this->value)),
        };

        return $this->parse($this->format($normalized), $maxLength);
    }

    /**
     * Formats a value of this type for storage, so that {@see parse()} returns it unchanged.
     *
     * @throws InvalidRuntimeSettingValue
     */
    public function format(string|int|float|bool $value): string
    {
        return match (true) {
            $this === self::String && is_string($value)                    => $value,
            $this === self::Int && is_int($value)                          => (string) $value,
            $this === self::Bool && is_bool($value)                        => $value ? 'true' : 'false',
            $this === self::Float && is_float($value) && is_finite($value) => self::formatFloat($value),
            default                                                        => throw InvalidRuntimeSettingValue::because(sprintf('expected a value of type %s', $this->value)),
        };
    }

    private static function formatFloat(float $value): string
    {
        $formatted = var_export($value, true);

        // var_export() writes 1.0E+25 for large values; the stored form uses a lowercase exponent without "+".
        return preg_match('/^(-?[0-9]+(?:\.[0-9]+)?)E([-+]?)([0-9]+)$/D', $formatted, $m) === 1
            ? $m[1] . 'e' . ($m[2] === '-' ? '-' : '') . $m[3]
            : $formatted;
    }
}
