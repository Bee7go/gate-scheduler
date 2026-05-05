<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StatisticsRequest;
use App\Services\Statistics\StatisticsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class StatisticsController extends Controller
{
    public function index(StatisticsRequest $request, StatisticsService $service): JsonResponse
    {
        $from = Carbon::parse($request->input('from'));
        $to = Carbon::parse($request->input('to'));

        $data = $service->generate($from, $to);

        return response()->json(['data' => $data]);
    }
}
