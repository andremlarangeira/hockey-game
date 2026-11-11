<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('championship_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('championship_id')->constrained('championships')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('short_name', 24)->nullable();
            $table->char('primary_color', 7)->default('#2563eb');
            $table->char('secondary_color', 7)->default('#ffffff');
            $table->string('logo_path', 255)->nullable();
            $table->string('group_code', 8)->nullable();
            $table->unsignedSmallInteger('group_draw_order')->nullable();
            $table->unsignedSmallInteger('seed_pot')->nullable();
            $table->timestamps();

            $table->unique(['championship_id', 'name']);
            $table->unique(['championship_id', 'group_code', 'group_draw_order'], 'championship_teams_group_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('championship_teams');
    }
};
