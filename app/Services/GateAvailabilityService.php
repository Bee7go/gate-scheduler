<?php

namespace App\Services;

use App\Models\Gate;
use DateTimeInterface;

class GateAvailabilityService
{
    public function isGateAvailable(int $gateId, DateTimeInterface $from, DateTimeInterface $until): bool
    {
        $gate = Gate::findOrFail($gateId);

        $hasAllocationConflict = $gate->allocations()
            ->where('occupied_from', '<', $until)
            ->where('occupied_until', '>', $from)
            ->exists();

        if ($hasAllocationConflict) {
            return false;
        }

        $hasUnavailabilityConflict = $gate->unavailabilities()
            ->where('start_at', '<', $until)
            ->where('end_at', '>', $from)
            ->exists();

        return !$hasUnavailabilityConflict;
    }
}
