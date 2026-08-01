<?php

declare(strict_types=1);

namespace App\Service;

use App\Enums\TagMatchType;
use App\Enums\TimeEntryType;
use App\Models\Member;
use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class TimeEntryFilter
{
    public const string NONE_VALUE = 'none';

    /**
     * @var Builder<TimeEntry>
     */
    private Builder $builder;

    /**
     * @param  Builder<TimeEntry>  $builder
     */
    public function __construct(Builder $builder)
    {
        $this->builder = $builder;
    }

    public function addEndFilter(?string $dateTime): self
    {
        if ($dateTime === null) {
            return $this;
        }
        $this->addEnd(Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $dateTime, 'UTC'));

        return $this;
    }

    public function addEnd(?Carbon $end): self
    {
        if ($end === null) {
            return $this;
        }
        $this->builder->where('start', '<', $end);

        return $this;
    }

    public function addStartFilter(?string $dateTime): self
    {
        if ($dateTime === null) {
            return $this;
        }
        $this->addStart(Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $dateTime, 'UTC'));

        return $this;
    }

    public function addStart(?Carbon $start): self
    {
        if ($start === null) {
            return $this;
        }
        $this->builder->where('start', '>=', $start);

        return $this;
    }

    public function addActiveFilter(?string $active): self
    {
        if ($active === null) {
            return $this;
        }
        if ($active === 'true') {
            $this->addActive(true);
        } elseif ($active === 'false') {
            $this->addActive(false);
        } else {
            Log::warning('Invalid active filter value', ['value' => $active]);
        }

        return $this;
    }

    public function addActive(?bool $active): self
    {
        if ($active) {
            $this->builder->whereNull('end');
        } else {
            $this->builder->whereNotNull('end');
        }

        return $this;
    }

    public function addMemberIdFilter(?Member $member): self
    {
        if ($member === null) {
            return $this;
        }
        $this->builder->where('member_id', $member->getKey());

        return $this;
    }

    /**
     * @param  array<string>|null  $memberIds
     */
    public function addMemberIdsFilter(?array $memberIds): self
    {
        if ($memberIds === null) {
            return $this;
        }
        $this->builder->whereIn('member_id', $memberIds);

        return $this;
    }

    public function addBillableFilter(?string $billable): self
    {
        if ($billable === null) {
            return $this;
        }
        if ($billable === 'true') {
            $this->addBillable(true);
        } elseif ($billable === 'false') {
            $this->addBillable(false);
        } else {
            Log::warning('Invalid billable filter value', ['value' => $billable]);
        }

        return $this;
    }

    public function addBillable(?bool $billable): self
    {
        if ($billable === null) {
            return $this;
        }
        $this->builder->where('billable', '=', $billable);

        return $this;
    }

    public function addTypeFilter(?string $type): self
    {
        if ($type === null) {
            return $this;
        }
        $typeEnum = TimeEntryType::tryFrom($type);
        if ($typeEnum === null) {
            Log::warning('Invalid type filter value', ['value' => $type]);

            return $this;
        }
        $this->addType($typeEnum);

        return $this;
    }

    public function addType(?TimeEntryType $type): self
    {
        if ($type === null) {
            return $this;
        }
        $this->builder->where('type', '=', $type->value);

        return $this;
    }

    /**
     * @param  array<string>|null  $clientIds
     */
    public function addClientIdsFilter(?array $clientIds): self
    {
        if ($clientIds === null) {
            return $this;
        }
        $includeNone = in_array(self::NONE_VALUE, $clientIds, true);
        $clientIds = array_values(array_filter($clientIds, fn (string $id): bool => $id !== self::NONE_VALUE));

        $this->builder->where(function (Builder $builder) use ($clientIds, $includeNone): void {
            if (count($clientIds) > 0) {
                $builder->whereIn('client_id', $clientIds);
            }
            if ($includeNone) {
                $builder->orWhereNull('client_id');
            }
        });

        return $this;
    }

    /**
     * @param  array<string>|null  $projectIds
     */
    public function addProjectIdsFilter(?array $projectIds): self
    {
        if ($projectIds === null) {
            return $this;
        }
        $includeNone = in_array(self::NONE_VALUE, $projectIds, true);
        $projectIds = array_values(array_filter($projectIds, fn (string $id): bool => $id !== self::NONE_VALUE));

        $this->builder->where(function (Builder $builder) use ($projectIds, $includeNone): void {
            if (count($projectIds) > 0) {
                $builder->whereIn('project_id', $projectIds);
            }
            if ($includeNone) {
                $builder->orWhereNull('project_id');
            }
        });

        return $this;
    }

    /**
     * @param  array<string>|null  $tagIds
     */
    public function addTagIdsFilter(?array $tagIds, ?TagMatchType $tagMatchType = TagMatchType::Contains): self
    {
        if ($tagIds === null) {
            return $this;
        }
        $tagMatchType ??= TagMatchType::Contains;
        $includeNone = in_array(self::NONE_VALUE, $tagIds, true);
        $tagIds = array_values(array_filter($tagIds, fn (string $id): bool => $id !== self::NONE_VALUE));
        // An empty selection (no tag IDs and not filtering for "none") is no constraint, so apply nothing.
        // This also prevents the not-contains branch from collapsing into "only entries with null tags".
        if (count($tagIds) === 0 && ! $includeNone) {
            return $this;
        }

        $tagCondition = function (Builder $builder) use ($tagIds, $includeNone): void {
            foreach ($tagIds as $tagId) {
                $builder->orWhereJsonContains('tags', $tagId);
            }
            if ($includeNone) {
                $builder->orWhere(function (Builder $query): void {
                    $query->whereJsonLength('tags', 0)->orWhereNull('tags');
                });
            }
        };

        if ($tagMatchType === TagMatchType::NotContains) {
            $this->builder->where(function (Builder $builder) use ($tagCondition, $includeNone): void {
                $builder->whereNot($tagCondition);
                if (! $includeNone) {
                    $builder->orWhereNull('tags');
                }
            });
        } else {
            $this->builder->where($tagCondition);
        }

        return $this;
    }

    /**
     * @param  array<string>|null  $taskIds
     */
    public function addTaskIdsFilter(?array $taskIds): self
    {
        if ($taskIds === null) {
            return $this;
        }
        $includeNone = in_array(self::NONE_VALUE, $taskIds, true);
        $taskIds = array_values(array_filter($taskIds, fn (string $id): bool => $id !== self::NONE_VALUE));

        $this->builder->where(function (Builder $builder) use ($taskIds, $includeNone): void {
            if (count($taskIds) > 0) {
                $builder->whereIn('task_id', $taskIds);
            }
            if ($includeNone) {
                $builder->orWhereNull('task_id');
            }
        });

        return $this;
    }

    /**
     * Drop time entries that belong to an internal project.
     *
     * Internal projects are own-business work (admin, sales, learning). They must never end up on
     * anything a client sees. Entries without a project are kept: they cannot be internal, and
     * dropping them would silently hide tracked hours from a specification that has to add up.
     */
    public function addExcludeInternalProjects(): self
    {
        $this->builder->whereDoesntHave('project', function (Builder $builder): void {
            /** @var Builder<Project> $builder */
            $builder->where('is_internal', '=', true);
        });

        return $this;
    }

    /**
     * Filter on a single metadata key.
     *
     * The driving use case is "which hours have not been invoiced yet": an external system stamps
     * f.e. `invoice_id` onto the entries it has processed, and then asks for the ones without it,
     * instead of trusting a date range to not overlap a previous run.
     *
     * @param  string|null  $value  Exact value the key must have. Null means "only check presence".
     * @param  bool  $exists  Whether the key must be present or absent.
     */
    public function addMetadataFilter(?string $key, ?string $value = null, bool $exists = true): self
    {
        if ($key === null) {
            return $this;
        }
        if (! $exists) {
            $this->builder->where(function (Builder $builder) use ($key): void {
                $builder->whereNull('metadata')
                    ->orWhereRaw('NOT (metadata::jsonb ?? ?)', [$key]);
            });

            return $this;
        }
        $this->builder->whereRaw('metadata::jsonb ?? ?', [$key]);
        if ($value !== null) {
            // Bound rather than built into a `metadata->key` path, so a key containing quotes or
            // arrows cannot change the meaning of the expression.
            $this->builder->whereRaw('metadata::jsonb ->> ? = ?', [$key, $value]);
        }

        return $this;
    }

    /**
     * @return Builder<TimeEntry>
     */
    public function get(): Builder
    {
        return $this->builder;
    }
}
