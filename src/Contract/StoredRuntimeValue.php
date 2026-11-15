<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

use DateTimeImmutable;
use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingSourceFailure;

/**
 * What a source holds for one setting: the current value and, during a rotation window, the previous one.
 *
 * Values are the stored strings (decrypted by the source); the resolver parses and validates them.
 */
final readonly class StoredRuntimeValue
{
    /**
     * @throws RuntimeSettingSourceFailure when only one of previous / previousExpiresAt is given
     */
    public function __construct(
        public string $current,
        public ?string $previous = null,
        public ?DateTimeImmutable $previousExpiresAt = null,
    ) {
        if (($previous === null) !== (!$previousExpiresAt instanceof DateTimeImmutable)) {
            throw RuntimeSettingSourceFailure::because('A previous value needs an expiry, and an expiry needs a previous value.');
        }
    }

    /**
     * The previous value while its window is open (strictly before the expiry), else null.
     */
    public function previousAt(DateTimeImmutable $now): ?string
    {
        return $this->previousExpiresAt instanceof DateTimeImmutable && $now < $this->previousExpiresAt ? $this->previous : null;
    }
}
