<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessToken extends Model
{
    use HasFactory;
    use Prunable;

    protected $fillable = [
        'user_id',
        'token',
        'expires_at',
        'last_used_at',
    ];

    protected $hidden = [
        'token',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Expired tokens can never authenticate again; the grace period only
     * keeps them visible for a short while (support, audit) before pruning.
     */
    public function prunable()
    {
        return static::where('expires_at', '<', now()->subDays(
            (int) config('services.auth.token_prune_after_days')
        ));
    }
}
