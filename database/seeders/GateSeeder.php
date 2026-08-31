<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $gates = [];

        foreach (range(1, 20) as $i) {
            $gates[] = [
                'code' => 'G'.$i,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('gates')->upsert($gates, ['code'], ['updated_at']);
    }
}
