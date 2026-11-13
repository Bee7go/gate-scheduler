<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->swapForeignKey(onDelete: 'cascade');
    }

    public function down(): void
    {
        $this->swapForeignKey(onDelete: 'set null');
    }

    private function swapForeignKey(string $onDelete): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('api_keys', function (Blueprint $table) use ($onDelete) {
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete($onDelete);
        });
    }
};
