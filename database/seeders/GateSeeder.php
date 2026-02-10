<?php

namespace Database\Seeders;

use App\Models\Gate;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // generate initial dataset with 20 gates
        $gates = [];

        foreach (range(1, 20) as $i) {
            $gates[] = [
                'code' => 'G' . $i,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('gates')->insert($gates);
    }
}
