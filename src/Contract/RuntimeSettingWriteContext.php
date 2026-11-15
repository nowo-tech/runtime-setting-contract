<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

use Nowo\RuntimeSettingContract\Contract\Exception\RuntimeSettingConfigurationError;

use function preg_match;

/**
 * Who makes a change, and how a writer fingerprints values for its audit entry.
 *
 * The actor is `cli:<os user>` for the console commands, or `user:<id>` / any `prefix:name` the admin UI uses.
 */
final readonly class RuntimeSettingWriteContext
{
    public const ACTOR_PATTERN = '/^[a-z][a-z0-9_]{0,15}:[A-Za-z0-9._@+-]{1,128}$/D';

    /**
     * @throws RuntimeSettingConfigurationError when the actor does not match {@see self::ACTOR_PATTERN}
     */
    public function __construct(
        public string $actor,
        private RuntimeValueFingerprinter $fingerprinter,
    ) {
        if (preg_match(self::ACTOR_PATTERN, $actor) !== 1) {
            throw RuntimeSettingConfigurationError::because('The actor must look like "cli:<user>" or "user:<id>".');
        }
    }

    /**
     * The fingerprint a writer records for a value of this setting, or null for a secret: a secret's audit entry
     * records only that it changed (audit QS-M-1).
     */
    public function fingerprint(RuntimeSettingDefinition $definition, ?string $value): ?string
    {
        return $definition->secret || $value === null ? null : $this->fingerprinter->fingerprint($definition->id, $value);
    }
}
