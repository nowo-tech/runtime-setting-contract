<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

/**
 * Where a setting's value comes from (host configuration `runtime.<setting>.source`).
 */
enum RuntimeSettingSource: string
{
    /** Today's behaviour: the configured (env) value only. Nothing reads the source; the setting cannot be written. */
    case Env = 'env';

    /** The source only. Unset means the bundle's documented default, or a failure when the setting is required. */
    case Db = 'db';

    /** The source first; unset falls back to the configured (env) value. */
    case DbThenEnv = 'db_then_env';
}
