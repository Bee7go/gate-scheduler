<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class SyncRun extends Model
{
    use HasFactory;
    use Prunable;

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

    /**
     * A sync runs every two minutes, so this table grows by roughly 263k
     * rows a year unless old runs are dropped after the retention window.
     */
    public function prunable()
    {
        return static::where('created_at', '<', now()->subDays(
            (int) config('services.sync.run_retention_days')
        ));
    }
}
