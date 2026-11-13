<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GateUnavailabilitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $gate = DB::table('gates')->where('code', 'G8')->first();

        if (! $gate) {
            return;
        }

        DB::table('gate_unavailabilities')->insert([
            'gate_id' => $gate->id,
            'start_at' => now()->subDay(),
            'end_at' => now()->addDays(2),
            'reason' => 'maintenance',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
