<?php

declare(strict_types=1);

namespace App\Enums;

use Datomatic\LaravelEnumHelper\LaravelEnumHelper;

/**
 * How a retainer's billing periods are derived.
 *
 * - Calendar: periods align to calendar boundaries (Monday-start weeks, calendar
 *   months, calendar quarters), independent of the retainer's own starts_at.
 * - Anchor: fixed-length periods walking forward from an explicit anchor_date, so
 *   period boundaries shift with the anchor rather than snapping to calendar edges.
 * - Explicit: periods are defined one-by-one via RetainerPeriod rows.
 */
enum RetainerPeriodMode: string
{
    use LaravelEnumHelper;

    case Calendar = 'calendar';
    case Anchor = 'anchor';
    case Explicit = 'explicit';
}
