<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('championships', function (Blueprint $table) {
            $table->string('public_token', 64)->nullable()->unique();
            $table->timestamp('public_enabled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('championships', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn(['public_token', 'public_enabled_at']);
        });
    }
};
