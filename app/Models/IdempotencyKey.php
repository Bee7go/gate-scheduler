<?php

namespace App\Models;

use App\Http\Middleware\EnforceIdempotency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * A recorded response for an Idempotency-Key header, replayed when the same
 * credential retries the same request within the retention window.
 */
class IdempotencyKey extends Model
{
    use Prunable;

    protected $fillable = [
        'credential',
        'idempotency_key',
        'request_hash',
        'response_status',
        'response_body',
    ];

    protected $casts = [
        'response_status' => 'integer',
    ];

    /**
     * Expired records hold encrypted response bodies and must not outlive
     * their replay window; the scheduler prunes them daily.
     */
    public function prunable()
    {
        return static::where('created_at', '<', now()->subHours(EnforceIdempotency::RETENTION_HOURS));
    }
}
