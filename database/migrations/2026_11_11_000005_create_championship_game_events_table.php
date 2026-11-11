<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('championship_game_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('championship_id')->constrained('championships')->cascadeOnDelete();
            $table->foreignId('championship_game_id')->constrained('championship_games')->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('side', 8)->nullable();
            $table->foreignId('team_id')->nullable()->constrained('championship_teams')->nullOnDelete();
            $table->foreignId('primary_player_id')->nullable()->constrained('championship_players')->nullOnDelete();
            $table->foreignId('first_assist_player_id')->nullable()->constrained('championship_players')->nullOnDelete();
            $table->foreignId('second_assist_player_id')->nullable()->constrained('championship_players')->nullOnDelete();
            $table->unsignedTinyInteger('period')->nullable();
            $table->boolean('is_overtime')->default(false);
            $table->unsignedInteger('elapsed_seconds')->default(0);
            $table->unsignedInteger('remaining_seconds')->default(0);
            $table->unsignedTinyInteger('shootout_round')->nullable();
            $table->boolean('shootout_scored')->nullable();
            $table->string('period_mark_type', 24)->nullable();
            $table->dateTime('recorded_at');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['championship_game_id', 'type', 'deleted_at'], 'champ_events_game_type_idx');
            $table->index(['championship_id', 'type', 'deleted_at', 'primary_player_id'], 'champ_events_scorer_idx');
            $table->index(['championship_id', 'type', 'deleted_at', 'first_assist_player_id'], 'champ_events_assist1_idx');
            $table->index(['championship_id', 'type', 'deleted_at', 'second_assist_player_id'], 'champ_events_assist2_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('championship_game_events');
    }
};
