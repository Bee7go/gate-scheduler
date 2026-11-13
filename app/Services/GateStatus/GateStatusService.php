<?php

namespace App\Services\GateStatus;

use App\Models\Gate;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class GateStatusService
{
    /**
     * Resolve each gate's status at the given moment.
     *
     * A gate is "occupied" while an allocation covers the moment,
     * "maintenance" while an unavailability covers it, and "free" otherwise.
     * Occupation takes precedence over maintenance.
     */
    public function statusAt(CarbonInterface $at, ?string $gateCode = null): Collection
    {
        $query = Gate::query()
            ->with([
                'allocations' => fn ($q) => $q
                    ->where('occupied_from', '<=', $at)
                    ->where('occupied_until', '>', $at)
                    ->with('flight'),
                'unavailabilities' => fn ($q) => $q
                    ->where('start_at', '<=', $at)
                    ->where('end_at', '>', $at),
            ]);

        if ($gateCode !== null) {
            $query->where('code', $gateCode);
        }

        return $query->orderBy('code')
            ->get()
            ->map(fn (Gate $gate) => $this->presentGate($gate));
    }

    private function presentGate(Gate $gate): array
    {
        $allocation = $gate->allocations->first();

        if ($allocation) {
            $flight = $allocation->flight;

            return [
                'gate_id' => $gate->id,
                'gate_code' => $gate->code,
                'status' => 'occupied',
                'occupied_until' => $allocation->occupied_until->toISOString(),
                'flight' => [
                    'id' => $flight->id,
                    'icao24' => $flight->icao24,
                    'direction' => $flight->direction,
                ],
            ];
        }

        return [
            'gate_id' => $gate->id,
            'gate_code' => $gate->code,
            'status' => $gate->unavailabilities->isNotEmpty() ? 'maintenance' : 'free',
            'occupied_until' => null,
            'flight' => null,
        ];
    }
}
