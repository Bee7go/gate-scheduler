<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gate extends Model
{
    use HasFactory;

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
