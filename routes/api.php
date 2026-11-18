<?php

use App\Http\Controllers\Api\V1\AllocationController;
use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\GateStatusController;
use App\Http\Controllers\Api\V1\GateUnavailabilityController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LoginController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\StatisticsController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\SystemStatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('/login', [LoginController::class, 'store']);
        Route::post('/register', [RegistrationController::class, 'store']);
    });

    Route::middleware('auth.bearer')->group(function () {
        Route::get('/api-keys', [ApiKeyController::class, 'index']);
        Route::post('/api-keys', [ApiKeyController::class, 'store'])->middleware('idempotency');
        Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->whereNumber('apiKey');
    });

    Route::middleware(['auth.apikey:read', 'throttle:api-key'])->group(function () {
        Route::get('/allocations', [AllocationController::class, 'index']);
        Route::get('/gates/status', [GateStatusController::class, 'index']);
        Route::get('/gates/unavailabilities', [GateUnavailabilityController::class, 'index']);
        Route::get('/system/health', [HealthController::class, 'index']);
        Route::get('/system/status', [SystemStatusController::class, 'index']);
        Route::get('/statistics', [StatisticsController::class, 'index']);
    });

    Route::post('/gates/unavailabilities', [GateUnavailabilityController::class, 'store'])
        ->middleware(['auth.apikey:write', 'throttle:api-key', 'idempotency']);

    Route::post('/system/sync-now', [SyncController::class, 'store'])
        ->middleware(['auth.apikey:sync', 'throttle:sync-now', 'throttle:api-key']);
});
