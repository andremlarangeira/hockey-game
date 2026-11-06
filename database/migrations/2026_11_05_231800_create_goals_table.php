<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('match_id')->constrained()->cascadeOnDelete();
            $table->string('team');
            $table->foreignId('scorer_id')->nullable()->constrained('players')->nullOnDelete();
            $table->foreignId('assist_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('scorer_name');
            $table->string('assist_name')->nullable();
            $table->unsignedTinyInteger('period');
            $table->unsignedInteger('elapsed_seconds');
            $table->unsignedInteger('remaining_seconds');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['user_id', 'scorer_id']);
            $table->index(['user_id', 'assist_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
