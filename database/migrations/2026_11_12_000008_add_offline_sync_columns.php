<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->timestamp('presence_changed_at')->nullable()->after('present');
        });
        Schema::table('matches', function (Blueprint $table): void {
            $table->uuid('client_uuid')->nullable()->unique()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table): void {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn('client_uuid');
        });
        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn('presence_changed_at');
        });
    }
};
