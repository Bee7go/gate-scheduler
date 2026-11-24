<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StatisticsRequest;
use App\Services\Statistics\StatisticsService;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Statistics', description: 'Period-scoped KPIs for dashboards.', weight: 4)]
class StatisticsController extends Controller
{
    /**
     * Get statistics
     *
     * Period-scoped KPIs for dashboards: gate utilization, flight allocation
     * rates, peak hour and day, top gates, and unavailability summaries.
     * The period may span at most 92 days (one full calendar quarter), since
     * peak detection scans the period hour by hour; aggregate across multiple
     * requests for longer horizons.
     */
    public function index(StatisticsRequest $request, StatisticsService $service): JsonResponse
    {
        $from = Carbon::parse($request->input('from'));
        $to = Carbon::parse($request->input('to'));

        $data = $service->generate($from, $to);

        return response()->json(['data' => $data]);
    }
}
