<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract\Exception;

use Throwable;

/**
 * Marker for every exception this package throws.
 *
 * Messages name the setting id at most, never a value: a value may be a secret.
 */
interface RuntimeSettingExceptionInterface extends Throwable
{
}
