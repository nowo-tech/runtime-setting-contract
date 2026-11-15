<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;
use SensitiveParameter;

use function hash_hmac;
use function strlen;
use function substr;

/**
 * Short keyed fingerprint of a stored value, for audit entries and command output: it shows that a value changed,
 * and lets an operator compare two values, without revealing the value.
 *
 * Keyed (HMAC-SHA-256 with a host secret, e.g. `kernel.secret`), so a low-entropy secret cannot be confirmed by
 * hashing guesses without the key. The id is part of the input, so equal values of two settings differ.
 */
final readonly class RuntimeValueFingerprinter
{
    public const MIN_KEY_LENGTH = 16;

    /**
     * @throws RuntimeSettingConfigurationError when the key is shorter than {@see self::MIN_KEY_LENGTH} bytes
     */
    public function __construct(
        #[SensitiveParameter]
        private string $key,
    ) {
        if (strlen($key) < self::MIN_KEY_LENGTH) {
            throw RuntimeSettingConfigurationError::because('The fingerprint key must be at least 16 bytes long.');
        }
    }

    public function fingerprint(RuntimeSettingId $id, string $value): string
    {
        return substr(hash_hmac('sha256', $id->toString() . "\0" . $value, $this->key), 0, 16);
    }
}
