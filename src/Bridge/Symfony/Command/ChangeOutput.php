<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Bridge\Symfony\Command;

use Nowo\RuntimeSettingContract\Contract\RuntimeSettingAction;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingChange;

use function sprintf;

/**
 * One line describing a change: id, action and fingerprints, never a value.
 *
 * @internal
 */
final class ChangeOutput
{
    public static function describe(RuntimeSettingChange $change): string
    {
        $verb = match ($change->action) {
            RuntimeSettingAction::Set    => 'set',
            RuntimeSettingAction::Unset  => 'unset',
            RuntimeSettingAction::Rotate => 'rotated',
        };

        if ($change->secret) {
            return $change->dryRun
                ? sprintf('Dry run: %s would be %s (secret: no fingerprint). Nothing was written.', $change->id->toString(), $verb)
                : sprintf('%s %s (secret: changed, no fingerprint). Running workers pick it up within the version TTL.', $change->id->toString(), $verb);
        }

        if ($change->dryRun) {
            return sprintf(
                'Dry run: %s would be %s%s. Nothing was written.',
                $change->id->toString(),
                $verb,
                $change->newFingerprint === null ? '' : ' (new fingerprint ' . $change->newFingerprint . ')',
            );
        }

        return sprintf(
            '%s %s (fingerprint %s -> %s). Running workers pick it up within the version TTL.',
            $change->id->toString(),
            $verb,
            $change->oldFingerprint ?? 'none',
            $change->newFingerprint ?? 'none',
        );
    }
}
