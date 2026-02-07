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
        Schema::create('flights', callback: function (Blueprint $table) {
            $table->id();

            $table->string('icao24'); // airplane id
            $table->string('airport_icao', 4);
            $table->string('direction', 10);

            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique(['icao24', 'airport_icao', 'direction']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flights');
    }
};
