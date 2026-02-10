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

    // @todo to be moved in a unit test
    // check function:
    /*
     *  use App\Services\GateAvailabilityService;
        $svc = new GateAvailabilityService();

        $from = now()->setDate(2026, 1, 13)->setTime(10, 0);
        $until = (clone $from)->addMinutes(90);

        $g8 = DB::table('gates')->where('code', 'G8')->first();
        $svc->isGateAvailable($g8->id, $from, $until);
     */

}
