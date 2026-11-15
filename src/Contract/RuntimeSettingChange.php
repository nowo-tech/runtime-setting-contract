<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

/**
 * Result of a write (or of a dry run): ids and fingerprints only, never a value.
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
    ) {
    }

    public function changed(): bool
    {
        return $this->oldFingerprint !== $this->newFingerprint || $this->action === RuntimeSettingAction::Rotate;
    }
}
