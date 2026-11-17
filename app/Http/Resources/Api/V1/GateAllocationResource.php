<?php

namespace App\Http\Resources\Api\V1;

use App\Models\GateAllocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin GateAllocation
 */
class GateAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'gate_id' => $this->gate_id,
            'flight_id' => $this->flight_id,
            'occupied_from' => $this->occupied_from,
            'occupied_until' => $this->occupied_until,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'gate' => new GateResource($this->whenLoaded('gate')),
            'flight' => new FlightResource($this->whenLoaded('flight')),
        ];
    }
}
