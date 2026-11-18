<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            // Which credential sent the key, e.g. "api-key:7" or "user:3";
            // idempotency keys are never shared across credentials.
            $table->string('credential');
            $table->string('idempotency_key');
            // Hash of method + path + query + body, to detect key reuse with a different request.
            $table->string('request_hash', 64);
            // Null while the first attempt is still executing (the reservation).
            $table->unsignedSmallInteger('response_status')->nullable();
            // Encrypted: recorded responses can carry secrets shown only once.
            $table->longText('response_body')->nullable();
            $table->timestamps();

            $table->unique(['credential', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
