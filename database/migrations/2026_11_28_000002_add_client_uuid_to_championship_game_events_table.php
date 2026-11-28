<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('championship_game_events', function (Blueprint $table): void {
            $table->uuid('client_uuid')->nullable()->after('championship_game_id');
            $table->unique(['championship_game_id', 'client_uuid'], 'champ_events_game_client_uuid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('championship_game_events', function (Blueprint $table): void {
            $table->dropUnique('champ_events_game_client_uuid_unique');
            $table->dropColumn('client_uuid');
        });
    }
};
