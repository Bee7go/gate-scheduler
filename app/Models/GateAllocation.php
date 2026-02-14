<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GateAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'gate_id',
        'flight_id',
        'occupied_from',
        'occupied_until',
    ];

    protected $casts = [
        'occupied_from' => 'datetime',
        'occupied_until' => 'datetime',
    ];

    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class);
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }
}
