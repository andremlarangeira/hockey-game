<?php

use App\Support\MatchPlayersBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        MatchPlayersBackfill::run();
    }

    public function down(): void
    {
        // No-op documentado: nunca apaga nem altera dados de `matches`;
        // a reversão estrutural da tabela ocorre na migration `create_match_players_table`.
    }
};
