<?php

declare(strict_types=1);

return [
    // Absolute ceiling for a single time entry's duration, in hours. Guards against
    // fat-fingered durations (e.g. a forgotten running timer) that would otherwise
    // create an entry spanning days or weeks and skew organization-wide reporting.
    'max_duration_hours' => (int) env('TIME_ENTRY_MAX_DURATION_HOURS', 168),
];
