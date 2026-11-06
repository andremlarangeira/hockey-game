<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 7)->default('#166534');
            $table->string('secondary_color', 7)->default('#f59e0b');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'primary_color', 'secondary_color']);
        });
    }
};
