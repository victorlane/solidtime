<?php

declare(strict_types=1);

namespace App\Enums;

use Datomatic\LaravelEnumHelper\LaravelEnumHelper;

/**
 * The recurrence unit for calendar/anchor retainer periods. Not used for explicit mode.
 */
enum RetainerPeriodUnit: string
{
    use LaravelEnumHelper;

    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
}
