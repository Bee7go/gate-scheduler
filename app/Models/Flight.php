<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property Carbon|null $first_seen_at
 * @property Carbon $id
 */
class Flight extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'first_seen_at',
        'last_seen_at', // probably not needed now
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime', // probably not needed now
    ];

    public function gateAllocation(): HasOne
    {
        return $this->hasOne(GateAllocation::class, 'flight_id');
    }
}
