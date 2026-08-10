<?php

declare(strict_types=1);

namespace App\Enums;

use Datomatic\LaravelEnumHelper\LaravelEnumHelper;

/**
 * What happens once a retainer's hard cap is exceeded.
 *
 * Only Block is implemented in v1 — Flag and Approval are reserved for a future
 * iteration and are treated as a no-op (saving is never prevented) until then.
 */
enum RetainerHardCapEnforcement: string
{
    use LaravelEnumHelper;

    case Block = 'block';
    case Flag = 'flag';
    case Approval = 'approval';
}
