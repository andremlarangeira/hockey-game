<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('championship_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('championship_id')->constrained('championships')->cascadeOnDelete();
            $table->string('phase', 24);
            $table->string('group_code', 8)->nullable();
            $table->unsignedSmallInteger('round_number')->default(1);
            $table->unsignedTinyInteger('leg_number')->default(1);
            $table->string('slot_key', 32);
            $table->unsignedSmallInteger('bracket_position')->nullable();
            $table->string('home_source', 48);
            $table->string('away_source', 48);
            $table->foreignId('home_team_id')->nullable()->constrained('championship_teams')->nullOnDelete();
            $table->foreignId('away_team_id')->nullable()->constrained('championship_teams')->nullOnDelete();
            $table->string('venue', 140)->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->string('status', 24)->default('scheduled');
            $table->boolean('is_bye')->default(false);
            $table->unsignedSmallInteger('home_regular_score')->default(0);
            $table->unsignedSmallInteger('away_regular_score')->default(0);
            $table->boolean('went_to_overtime')->default(false);
            $table->boolean('went_to_shootout')->default(false);
            $table->unsignedSmallInteger('home_shootout_score')->default(0);
            $table->unsignedSmallInteger('away_shootout_score')->default(0);
            $table->foreignId('winner_team_id')->nullable()->constrained('championship_teams')->nullOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['championship_id', 'slot_key']);
            $table->unique(
                ['championship_id', 'phase', 'group_code', 'leg_number', 'home_team_id', 'away_team_id'],
                'championship_games_unique_fixture',
            );
            $table->index(['championship_id', 'phase', 'status']);
            $table->index(['championship_id', 'group_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('championship_games');
    }
};
