<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 */
class Flight extends Model
{
    use HasFactory;

    protected $fillable = [
        'icao24',
        'airport_icao',
        'direction',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function gateAllocation(): HasOne
    {
        return $this->hasOne(GateAllocation::class, 'flight_id');
    }
}
