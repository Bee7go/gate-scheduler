<?php

use App\Http\Controllers\Api\V1\AllocationController;
use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\GateStatusController;
use App\Http\Controllers\Api\V1\GateUnavailabilityController;
use App\Http\Controllers\Api\V1\LoginController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/login', [LoginController::class, 'store']);
    Route::post('/register', [RegistrationController::class, 'store']);

    Route::middleware('auth.bearer')->group(function () {
        Route::post('/api-keys', [ApiKeyController::class, 'store']);
    });

    Route::middleware('auth.apikey')->group(function () {
        Route::get('/allocations', [AllocationController::class, 'index']);
        Route::get('/gates/status', [GateStatusController::class, 'index']);
        Route::get('/gates/unavailabilities', [GateUnavailabilityController::class, 'index']);
        Route::post('/gates/unavailabilities', [GateUnavailabilityController::class, 'store']);
        Route::post('/system/sync-now', [SyncController::class, 'store'])
            ->middleware('throttle:sync-now');
    });
});
