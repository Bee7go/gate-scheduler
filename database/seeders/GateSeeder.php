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
        // generate 20 new gates
        $gateCount = DB::table('gates')->count();
        $startNumber = $gateCount + 1;
        
        $gates = [];

        foreach (range($startNumber, $startNumber + 19) as $i) {
            $gates[] = [
                'code' => 'G' . $i,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('gates')->insert($gates);
    }
}
