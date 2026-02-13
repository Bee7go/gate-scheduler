<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gate extends Model
{
    protected $fillable = [
        'code',
    ];

    public function allocations(): HasMany
    {
        return $this->hasMany(GateAllocation::class);
    }

    public function unavailabilities(): HasMany
    {
        return $this->hasMany(GateUnavailability::class);
    }
}
