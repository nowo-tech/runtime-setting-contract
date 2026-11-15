<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

/**
 * Result of a write (or of a dry run): ids and fingerprints only, never a value.
 *
 * For a secret both fingerprints are always null (audit QS-M-1): a fingerprint of a secret would let anyone allowed to
 * write it confirm guesses of the current value.
 */
final readonly class RuntimeSettingChange
{
    public function __construct(
        public RuntimeSettingId $id,
        public RuntimeSettingAction $action,
        public ?string $oldFingerprint,
        public ?string $newFingerprint,
        public ?string $version,
        public bool $dryRun = false,
        public bool $secret = false,
    ) {
    }

    /**
     * Whether the stored value changed. Secrets and rotations always count as changed: their fingerprints are not
     * compared.
     */
    public function changed(): bool
    {
        return $this->secret || $this->action === RuntimeSettingAction::Rotate || $this->oldFingerprint !== $this->newFingerprint;
    }

    /**
     * The same change with both fingerprints removed, for secrets.
     */
    public function withoutFingerprints(): self
    {
        return new self($this->id, $this->action, null, null, $this->version, $this->dryRun, true);
    }
}
