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
 * Keyed (HMAC-SHA-256), so a value cannot be confirmed by hashing guesses without the key. The id is part of the
 * input, so equal values of two settings differ. The key is a subkey derived from the host secret (for example
 * `kernel.secret`) with the label {@see self::DOMAIN}, so these MACs never share a key with the host's other uses of that
 * secret (audit QS-L-3). Rotating the host secret changes every fingerprint.
 *
 * Fingerprints of secrets are never produced by the updater or recorded by writers (audit QS-M-1): use this class for
 * non-secret values only.
 */
final readonly class RuntimeValueFingerprinter
{
    public const MIN_KEY_LENGTH = 16;

    public const DOMAIN = 'nowo.runtime-setting.fingerprint.v1';

    private string $key;

    /**
     * @throws RuntimeSettingConfigurationError when the key is shorter than {@see self::MIN_KEY_LENGTH} bytes
     */
    public function __construct(
        #[SensitiveParameter]
        string $key,
    ) {
        if (strlen($key) < self::MIN_KEY_LENGTH) {
            throw RuntimeSettingConfigurationError::because('The fingerprint key must be at least 16 bytes long.');
        }
        $this->key = hash_hmac('sha256', self::DOMAIN, $key, true);
    }

    public function fingerprint(RuntimeSettingId $id, string $value): string
    {
        return substr(hash_hmac('sha256', $id->toString() . "\0" . $value, $this->key), 0, 16);
    }
}
