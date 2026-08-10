<?php

declare(strict_types=1);

namespace App\Enums;

use Datomatic\LaravelEnumHelper\LaravelEnumHelper;

/**
 * Whether a retainer's hard cap (and any per-project sub-caps) is checked against the
 * current period's prorated allocation, or against a single fixed cumulative budget.
 */
enum RetainerHardCapScope: string
{
    use LaravelEnumHelper;

    case PerPeriod = 'per_period';
    case Cumulative = 'cumulative';
}
