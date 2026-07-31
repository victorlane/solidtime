<?php

declare(strict_types=1);

namespace App\Service\ReportExport;

use App\Enums\TimeEntryType;
use App\Models\TimeEntry;
use App\Service\IntervalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends CsvExport<TimeEntry>
 */
class TimeEntriesDetailedCsvExport extends CsvExport
{
    public const array HEADER = [
        'Description',
        'Task',
        'Project',
        'Client',
        'User',
        'Start',
        'End',
        'Duration',
        'Duration (decimal)',
        'Billable',
        'Break',
        'Tags',
    ];

    protected const string CARBON_FORMAT = 'Y-m-d H:i:s';

    private string $timezone;

    /**
     * @var list<string>
     */
    private array $metadataKeys;

    /**
     * @param  Builder<TimeEntry>  $builder
     * @param  list<string>  $metadataKeys  Metadata keys that get one column each, appended after the fixed columns.
     */
    public function __construct(string $disk, string $folderPath, string $filename, Builder $builder, int $chunk, string $timezone, array $metadataKeys = [])
    {
        parent::__construct($disk, $folderPath, $filename, $builder, $chunk);

        $this->timezone = $timezone;
        $this->metadataKeys = $metadataKeys;
    }

    /**
     * @return list<string>
     */
    protected function header(): array
    {
        return array_merge(static::HEADER, MetadataColumns::headings($this->metadataKeys));
    }

    /**
     * @param  TimeEntry  $model
     */
    public function mapRow(Model $model): array
    {
        $interval = app(IntervalService::class);
        $duration = $model->getDuration();

        return array_merge([
            'Description' => $model->description,
            'Task' => $model->task?->name,
            'Project' => $model->project?->name,
            'Client' => $model->client?->name,
            'User' => $model->user->name,
            'Start' => $model->start->timezone($this->timezone),
            'End' => $model->end->timezone($this->timezone),
            'Duration' => $duration !== null ? $interval->format($model->getDuration()) : null,
            'Duration (decimal)' => $duration?->totalHours,
            'Billable' => $model->billable ? 'Yes' : 'No',
            'Break' => $model->type === TimeEntryType::Break ? 'Yes' : 'No',
            'Tags' => $model->tagsRelation->pluck('name')->implode(', '),
        ], MetadataColumns::row($model, $this->metadataKeys));
    }
}
