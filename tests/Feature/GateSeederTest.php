<?php

namespace Tests\Feature;

use Database\Seeders\GateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GateSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_standard_gate_codes(): void
    {
        $this->seed(GateSeeder::class);

        $this->assertDatabaseCount('gates', 20);
        $this->assertDatabaseHas('gates', ['code' => 'G1']);
        $this->assertDatabaseHas('gates', ['code' => 'G20']);
    }

    public function test_it_can_be_run_repeatedly_without_creating_duplicate_gates(): void
    {
        $this->seed(GateSeeder::class);
        $this->seed(GateSeeder::class);

        $this->assertDatabaseCount('gates', 20);
    }
}
