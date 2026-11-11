<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('championship_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('championship_id')->constrained('championships')->cascadeOnDelete();
            $table->foreignId('championship_team_id')->constrained('championship_teams')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('jersey_number');
            $table->string('position', 24)->default('universal');
            $table->boolean('is_goalkeeper')->default(false);
            $table->timestamps();

            $table->unique(['championship_team_id', 'jersey_number']);
            $table->index(['championship_id', 'championship_team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('championship_players');
    }
};
