<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('jersey_number');
            $table->string('photo_path')->nullable();
            $table->decimal('level', 2, 1);
            $table->boolean('present')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'jersey_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
