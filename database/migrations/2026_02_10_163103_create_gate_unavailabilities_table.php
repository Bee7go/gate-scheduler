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
        Schema::create('gate_unavailabilities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('gate_id')->constrained('gates')->cascadeOnDelete();

            $table->dateTime('start_at');
            $table->dateTime('end_at');

            $table->string('reason')->nullable();

            $table->timestamps();

            $table->index(['gate_id', 'start_at', 'end_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gate_unavailabilities');
    }
};
