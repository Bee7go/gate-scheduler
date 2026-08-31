<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    protected $fillable = [
        'trigger',
        'status',
        'arrivals_source',
        'departures_source',
        'arrivals_fetched',
        'departures_fetched',
        'allocation_summary',
        'failure_reason',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'allocation_summary' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
