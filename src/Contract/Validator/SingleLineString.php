<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract\Validator;

use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingValue;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingValidatorInterface;

use function is_string;
use function preg_match;
use function sprintf;
use function strlen;

/**
 * A string on one line: no control characters (C0, DEL, and the C1 range), at most `maxLength` bytes, optionally
 * non-empty. Use it for anything that may end up in a header, a DSN, a URL or a log line.
 */
final readonly class SingleLineString implements RuntimeSettingValidatorInterface
{
    /**
     * @throws RuntimeSettingConfigurationError when maxLength is below 1
     */
    public function __construct(
        public int $maxLength = 255,
        public bool $allowEmpty = false,
    ) {
        if ($maxLength < 1) {
            throw RuntimeSettingConfigurationError::because('SingleLineString: maxLength must be at least 1.');
        }
    }

    public function validate(string|int|float|bool $value): string
    {
        if (!is_string($value)) {
            throw InvalidRuntimeSettingValue::because('expected a string');
        }
        if (strlen($value) > $this->maxLength) {
            throw InvalidRuntimeSettingValue::because(sprintf('longer than %d bytes', $this->maxLength));
        }
        if ($value === '' && !$this->allowEmpty) {
            throw InvalidRuntimeSettingValue::because('must not be empty');
        }
        if (preg_match('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', $value) === 1) {
            throw InvalidRuntimeSettingValue::because('must be a single line without control characters');
        }

        return $value;
    }
}
