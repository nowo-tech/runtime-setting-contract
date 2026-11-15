<?php

declare(strict_types=1);

namespace Nowo\RuntimeSettingContract\Contract;

enum RuntimeSettingAction: string
{
    case Set = 'set';

    case Unset = 'unset';

    case Rotate = 'rotate';
}
