<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    use HasFactory;

    /**
     * Every scope an API key can carry. A key with `scopes = null` is
     * unrestricted (keys issued before scopes existed keep working).
     */
    public const SCOPES = ['read', 'write', 'sync'];

    protected $fillable = ['user_id', 'name', 'description', 'key', 'scopes', 'rate_limit_per_minute', 'last_used_at', 'expires_at'];

    protected $hidden = ['key'];

    protected $casts = [
        'scopes' => 'array',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function allows(string $scope): bool
    {
        return $this->scopes === null || in_array($scope, $this->scopes, true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
