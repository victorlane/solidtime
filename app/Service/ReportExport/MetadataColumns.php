<?php

declare(strict_types=1);

namespace App\Service\ReportExport;

use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Turns the free-form metadata of time entries into a fixed set of export columns.
 *
 * Metadata keys differ per time entry, so the columns of an export are only known
 * after looking at every entry that is part of it.
 */
class MetadataColumns
{
    /**
     * Upper bound on the number of metadata columns, so that an organization with many
     * distinct keys can not blow up the width of an export.
     */
    public const int MAX_KEYS = 50;

    private const string HEADING_PREFIX = 'Metadata: ';

    /**
     * Collect the distinct metadata keys of all time entries the query matches.
     *
     * @param  Builder<TimeEntry>  $builder
     * @return list<string>
     */
    public static function collectKeys(Builder $builder): array
    {
        $keys = [];

        (clone $builder)
            ->withOnly([])
            ->reorder()
            ->select(['time_entries.id', 'time_entries.metadata'])
            ->whereNotNull('time_entries.metadata')
            ->chunkById(1000, function (Collection $timeEntries) use (&$keys): void {
                /** @var TimeEntry $timeEntry */
                foreach ($timeEntries as $timeEntry) {
                    foreach (array_keys($timeEntry->metadata ?? []) as $key) {
                        $keys[$key] = true;
                    }
                }
            });

        $keys = array_keys($keys);
        sort($keys);

        return array_slice($keys, 0, self::MAX_KEYS);
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function headings(array $keys): array
    {
        return array_map(fn (string $key): string => self::HEADING_PREFIX.$key, $keys);
    }

    /**
     * The metadata of a time entry keyed by heading, for exports that map rows by column name.
     *
     * @param  list<string>  $keys
     * @return array<string, string|null>
     */
    public static function row(TimeEntry $timeEntry, array $keys): array
    {
        $row = [];
        foreach ($keys as $key) {
            $row[self::HEADING_PREFIX.$key] = $timeEntry->metadata[$key] ?? null;
        }

        return $row;
    }

    /**
     * The metadata of a time entry in the order of the given keys, for exports that map rows by position.
     *
     * @param  list<string>  $keys
     * @return list<string|null>
     */
    public static function values(TimeEntry $timeEntry, array $keys): array
    {
        return array_map(fn (string $key): ?string => $timeEntry->metadata[$key] ?? null, $keys);
    }
}
