<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GateUnavailability extends Model
{
    use HasFactory;
    protected $fillable = [
        'gate_id',
        'start_at',
        'end_at',
        'reason',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class);
    }
}
