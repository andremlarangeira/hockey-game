<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table): void {
            if (! Schema::hasColumn('matches', 'public_token'))
            {
                $table->string('public_token', 64)->nullable()->unique();
            }

            if (! Schema::hasColumn('matches', 'public_enabled_at'))
            {
                $table->timestamp('public_enabled_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table): void {
            if (Schema::hasColumn('matches', 'public_token'))
            {
                $table->dropUnique(['public_token']);
                $table->dropColumn('public_token');
            }

            if (Schema::hasColumn('matches', 'public_enabled_at'))
            {
                $table->dropColumn('public_enabled_at');
            }
        });
    }
};
