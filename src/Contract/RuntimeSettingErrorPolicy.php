<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

/**
 * What happens when the source cannot be read or holds a value the validator refuses (host configuration `on_error`).
 */
enum RuntimeSettingErrorPolicy: string
{
    /** Log a warning (id only) and use the configured (env) value. */
    case Fallback = 'fallback';

    /** Abort the operation: the resolver throws. The default; required for settings that delete data or affect security. */
    case FailClosed = 'fail_closed';
}
