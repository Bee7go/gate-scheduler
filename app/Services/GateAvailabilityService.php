<?php

namespace App\Services;

use App\Models\Gate;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

class GateAvailabilityService {
    /**
     * Checks if a gate is available for a given time range
     *
     * @param int $gateId
     * @param DateTimeInterface $from
     * @param DateTimeInterface $until
     * @return bool
     */
    public function isGateAvailable(int $gateId, DateTimeInterface $from, DateTimeInterface $until): bool
    {
        if ($gateId <= 0) {
            throw new InvalidArgumentException('Gate ID must be a positive integer.');
        }

        if ($from >= $until) {
            throw new InvalidArgumentException(
                'Invalid time range: "from" must be earlier than "until".'
            );
        }

        $gate = Gate::find($gateId);

        if (!$gate) {
            throw new ModelNotFoundException("Gate {$gateId} not found.");
        }

        // Check for gate allocation conflicts
        $hasAllocationConflict = $gate->allocations()
            ->where('occupied_from', '<', $until)
            ->where('occupied_until', '>', $from)
            ->exists();

        if ($hasAllocationConflict) {
            return false;
        }

        // Check for gate unavailability conflicts
        $hasUnavailabilityConflict = $gate->unavailabilities()
            ->where('start_at', '<', $until)
            ->where('end_at', '>', $from)
            ->exists();

        return !$hasUnavailabilityConflict;
    }
}
