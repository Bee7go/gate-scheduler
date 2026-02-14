<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class GateAvailabilityService
{
    public function isGateAvailable(int $gateId, DateTimeInterface $from, DateTimeInterface $until): bool
    {
        $hasAllocationConflict = DB::table('gate_allocations')
            ->where('gate_id', $gateId)
            ->where('occupied_from', '<', $until)
            ->where('occupied_until', '>', $from)
            ->exists();

        if ($hasAllocationConflict) {
            return false;
        }

        $hasUnavailabilityConflict = DB::table('gate_unavailabilities')
            ->where('gate_id', $gateId)
            ->where('start_at', '<', $until)
            ->where('end_at', '>', $from)
            ->exists();

        return !$hasUnavailabilityConflict;
    }
}
