<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract\Exception;

use OutOfBoundsException;

use function sprintf;

/**
 * No bundle registered a definition for this id.
 */
final class UnknownRuntimeSetting extends OutOfBoundsException implements RuntimeSettingExceptionInterface
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Unknown runtime setting "%s": only settings that a bundle registered can be read or written.', $id));
    }
}
