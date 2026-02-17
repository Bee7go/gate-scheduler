<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('gate_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('gate_id')
                ->constrained('gates')
                ->cascadeOnDelete();

            $table->foreignId('flight_id')
                ->constrained('flights')
                ->cascadeOnDelete();

            $table->dateTime('occupied_from');
            $table->dateTime('occupied_until');

            $table->timestamps();

            $table->unique('flight_id');

            $table->index(['gate_id', 'occupied_from', 'occupied_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gate_allocations');
    }
};
