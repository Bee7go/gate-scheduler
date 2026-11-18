<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            // null means unrestricted: keys issued before scopes existed keep working.
            $table->json('scopes')->nullable()->after('key');
            // null means the configurable default applies.
            $table->unsignedInteger('rate_limit_per_minute')->nullable()->after('scopes');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn(['scopes', 'rate_limit_per_minute']);
        });
    }
};
