<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract\Exception;

use InvalidArgumentException;

/**
 * The id is neither `namespace.key` nor an environment variable name. The rejected input is not echoed.
 */
final class InvalidRuntimeSettingId extends InvalidArgumentException implements RuntimeSettingExceptionInterface
{
    public static function malformed(): self
    {
        return new self('Invalid runtime setting id: use "namespace.key" (lowercase) or an environment variable name (A-Z, 0-9, _).');
    }
}
