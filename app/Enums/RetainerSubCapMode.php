<?php

declare(strict_types=1);

namespace App\Enums;

use Datomatic\LaravelEnumHelper\LaravelEnumHelper;

/**
 * Whether per-project RetainerProjectCap rows actually block saving (Strict) or are
 * purely informational for the status UI (Soft).
 */
enum RetainerSubCapMode: string
{
    use LaravelEnumHelper;

    case Soft = 'soft';
    case Strict = 'strict';
}
