<?php

declare(strict_types=1);

namespace App\Service\Retainer;

use App\Http\Controllers\Api\V1\RetainerController;
use App\Models\Retainer;
use Illuminate\Support\Carbon;

/**
 * Finds the retainer active for a client on a given date. Overlap between two
 * retainers of the same client is prevented on write (see
 * {@see RetainerController::assertNoOverlap()}), so at
 * most one retainer can ever be active for a client on any given day.
 */
class RetainerLookup
{
    public function findActiveForClient(string $clientId, Carbon $date): ?Retainer
    {
        $day = $date->copy()->startOfDay();

        return Retainer::query()
            ->where('client_id', $clientId)
            ->whereDate('starts_at', '<=', $day)
            ->where(function ($query) use ($day): void {
                $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', $day);
            })
            ->first();
    }
}
