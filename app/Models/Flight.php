<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Flight extends Model
{
    protected $fillable = [
        'code',
        'first_seen_at',
        'last_seen_at', // @todo probably not needed now
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
