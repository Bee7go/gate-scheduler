<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GateUnavailabilitiesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $gate = DB::table('gates')->where('code', 'G8')->first();

        if (!$gate) {
            return;
        }

        // @todo de gasit niste date mock mai diverse

        DB::table('gate_unavailabilities')->insert([
            'gate_id' => $gate->id,
            'start_at' => '2026-01-10 00:00:00',
            'end_at' => '2026-03-11 23:59:59',
            'reason' => 'maintenance',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
