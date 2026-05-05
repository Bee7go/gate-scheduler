<?php

namespace App\Services\Statistics;

use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateUnavailability;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StatisticsService
{
    public function generate(Carbon $from, Carbon $to): array
    {
        return [
            'period'         => $this->period($from, $to),
            'gates'          => $this->gates($from, $to),
            'flights'        => $this->flights($from, $to),
            'allocations'    => $this->allocations($from, $to),
            'peak'           => $this->peak($from, $to),
            'top_gates'      => $this->topGates($from, $to),
            'unavailability' => $this->unavailability($from, $to),
            'generated_at'   => now()->toIso8601String(),
        ];
    }

    private function period(Carbon $from, Carbon $to): array
    {
        return [
            'from' => $from->toIso8601String(),
            'to'   => $to->toIso8601String(),
        ];
    }

    private function gates(Carbon $from, Carbon $to): array
    {
        $totalGates = Gate::count();

        $activeGates = Gate::whereHas('allocations', function ($q) use ($from, $to) {
            $q->where('occupied_from', '<', $to)
              ->where('occupied_until', '>', $from);
        })->count();

        $hadUnavailability = Gate::whereHas('unavailabilities', function ($q) use ($from, $to) {
            $q->where('start_at', '<', $to)
              ->where('end_at', '>', $from);
        })->count();

        $periodMinutes = $from->diffInMinutes($to);
        $totalCapacityMinutes = $totalGates * $periodMinutes;

        $utilizationRate = 0;
        $averageTurnaroundMinutes = null;

        if ($totalCapacityMinutes > 0) {
            $allocations = GateAllocation::where('occupied_from', '<', $to)
                ->where('occupied_until', '>', $from)
                ->get(['occupied_from', 'occupied_until']);

            $occupiedMinutes = $allocations->sum(function ($a) use ($from, $to) {
                $start = $a->occupied_from->max($from);
                $end = $a->occupied_until->min($to);

                return $start->diffInMinutes($end);
            });

            $utilizationRate = round($occupiedMinutes / $totalCapacityMinutes, 2);
        }

        // Average turnaround: avg gap between consecutive allocations on the same gate
        $periodAllocations = GateAllocation::where('occupied_from', '<', $to)
            ->where('occupied_until', '>', $from)
            ->orderBy('gate_id')
            ->orderBy('occupied_from')
            ->get(['gate_id', 'occupied_from', 'occupied_until']);

        $gaps = [];
        $grouped = $periodAllocations->groupBy('gate_id');
        foreach ($grouped as $gateAllocations) {
            $sorted = $gateAllocations->values();
            for ($i = 0; $i < $sorted->count() - 1; $i++) {
                $gap = $sorted[$i]->occupied_until->diffInMinutes($sorted[$i + 1]->occupied_from);
                $gaps[] = $gap;
            }
        }

        if (count($gaps) > 0) {
            $averageTurnaroundMinutes = (int) round(array_sum($gaps) / count($gaps));
        }

        return [
            'total'                     => $totalGates,
            'active'                    => $activeGates,
            'had_unavailability'        => $hadUnavailability,
            'utilization_rate'          => $utilizationRate,
            'average_turnaround_minutes' => $averageTurnaroundMinutes,
        ];
    }

    private function flights(Carbon $from, Carbon $to): array
    {
        $query = Flight::where('first_seen_at', '>=', $from)
            ->where('first_seen_at', '<', $to);

        $total = (clone $query)->count();
        $arrivals = (clone $query)->where('direction', 'arrival')->count();
        $departures = (clone $query)->where('direction', 'departure')->count();

        $unallocated = (clone $query)->whereDoesntHave('gateAllocation')->count();

        $allocationRate = $total > 0 ? round(($total - $unallocated) / $total, 2) : 0;

        return [
            'total'           => $total,
            'arrivals'        => $arrivals,
            'departures'      => $departures,
            'unallocated'     => $unallocated,
            'allocation_rate' => $allocationRate,
        ];
    }

    private function allocations(Carbon $from, Carbon $to): array
    {
        $allocations = GateAllocation::where('occupied_from', '<', $to)
            ->where('occupied_until', '>', $from)
            ->get(['occupied_from', 'occupied_until']);

        $total = $allocations->count();

        $durations = $allocations->map(fn ($a) => $a->occupied_from->diffInMinutes($a->occupied_until));

        return [
            'total'                      => $total,
            'average_duration_minutes'   => $total > 0 ? (int) round($durations->avg()) : null,
            'shortest_duration_minutes'  => $total > 0 ? (int) $durations->min() : null,
            'longest_duration_minutes'   => $total > 0 ? (int) $durations->max() : null,
        ];
    }

    private function peak(Carbon $from, Carbon $to): array
    {
        // Busiest hour: for each hour slot, count overlapping allocations
        $busiestHour = null;
        $maxOccupied = 0;

        $cursor = $from->copy()->startOfHour();
        while ($cursor->lt($to)) {
            $hourEnd = $cursor->copy()->addHour();

            $count = GateAllocation::where('occupied_from', '<', $hourEnd)
                ->where('occupied_until', '>', $cursor)
                ->count();

            if ($count > $maxOccupied) {
                $maxOccupied = $count;
                $busiestHour = $cursor->format('H:i');
            }

            $cursor->addHour();
        }

        // Busiest date: count allocations per day
        $busiestDate = null;
        $busiestDateAllocations = 0;

        $dayCursor = $from->copy()->startOfDay();
        while ($dayCursor->lt($to)) {
            $dayEnd = $dayCursor->copy()->addDay();

            $count = GateAllocation::where('occupied_from', '<', $dayEnd)
                ->where('occupied_until', '>', $dayCursor)
                ->count();

            if ($count > $busiestDateAllocations) {
                $busiestDateAllocations = $count;
                $busiestDate = $dayCursor->format('Y-m-d');
            }

            $dayCursor->addDay();
        }

        return [
            'busiest_hour'              => $busiestHour,
            'max_simultaneous_gates'    => $maxOccupied,
            'busiest_date'              => $busiestDate,
            'busiest_date_allocations'  => $busiestDateAllocations,
        ];
    }

    private function topGates(Carbon $from, Carbon $to): array
    {
        return GateAllocation::select('gate_id', DB::raw('COUNT(*) as allocations_count'))
            ->where('occupied_from', '<', $to)
            ->where('occupied_until', '>', $from)
            ->groupBy('gate_id')
            ->orderByDesc('allocations_count')
            ->limit(5)
            ->get()
            ->map(function ($row) {
                $gate = Gate::find($row->gate_id);

                return [
                    'gate_code'         => $gate->code,
                    'allocations_count' => (int) $row->allocations_count,
                ];
            })
            ->values()
            ->toArray();
    }

    private function unavailability(Carbon $from, Carbon $to): array
    {
        $events = GateUnavailability::where('start_at', '<', $to)
            ->where('end_at', '>', $from);

        $totalEvents = (clone $events)->count();

        $affectedGates = (clone $events)->distinct('gate_id')->count('gate_id');

        $totalDowntimeMinutes = 0;
        if ($totalEvents > 0) {
            $totalDowntimeMinutes = (clone $events)->get(['start_at', 'end_at'])
                ->sum(function ($u) use ($from, $to) {
                    $start = $u->start_at->max($from);
                    $end = $u->end_at->min($to);

                    return $start->diffInMinutes($end);
                });
        }

        // Most common reason (excluding nulls)
        $mostCommonReason = (clone $events)
            ->whereNotNull('reason')
            ->select('reason', DB::raw('COUNT(*) as cnt'))
            ->groupBy('reason')
            ->orderByDesc('cnt')
            ->first();

        return [
            'total_events'           => $totalEvents,
            'total_downtime_minutes' => (int) $totalDowntimeMinutes,
            'affected_gates'         => $affectedGates,
            'most_common_reason'     => $mostCommonReason?->reason,
        ];
    }
}
