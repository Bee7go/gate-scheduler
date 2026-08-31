<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger');
            $table->string('status')->index();
            $table->string('arrivals_source')->nullable();
            $table->string('departures_source')->nullable();
            $table->unsignedInteger('arrivals_fetched')->nullable();
            $table->unsignedInteger('departures_fetched')->nullable();
            $table->json('allocation_summary')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
