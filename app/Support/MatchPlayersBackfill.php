<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

final class MatchPlayersBackfill
{
    private const REMOVED_PLAYER_LABEL = 'Jogador removido';

    public static function run(int $chunkSize = 100): int
    {
        if (! Schema::hasTable('matches') || ! Schema::hasTable('match_players'))
        {
            return 0;
        }

        $insertedTotal = 0;

        DB::table('matches')
            ->select(['id', 'user_id', 'roster', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $matches) use (&$insertedTotal): void {
                $insertedTotal += self::processChunk($matches);
            });

        return $insertedTotal;
    }

    /**
     * @param  Collection<int, stdClass>  $matches
     */
    private static function processChunk(Collection $matches): int
    {
        if ($matches->isEmpty())
        {
            return 0;
        }

        /** @var array<int, array<string, mixed>> $decodedByMatchId */
        $decodedByMatchId = [];
        $candidatePlayerIds = [];
        $matchIds = [];

        foreach ($matches as $match)
        {
            $matchId = (int) $match->id;
            $matchIds[] = $matchId;

            $decoded = is_string($match->roster) ? json_decode($match->roster, true) : null;
            if (! is_array($decoded))
            {
                continue;
            }

            $decodedByMatchId[$matchId] = $decoded;

            foreach (['home', 'away'] as $side)
            {
                $players = $decoded[$side]['players'] ?? null;
                if (! is_array($players))
                {
                    continue;
                }

                foreach ($players as $player)
                {
                    if (is_array($player) && isset($player['id']) && is_numeric($player['id']))
                    {
                        $candidatePlayerIds[] = (int) $player['id'];
                    }
                }
            }
        }

        if ($decodedByMatchId === [])
        {
            return 0;
        }

        /** @var array<int, array<int, true>> $validPlayersByUser */
        $validPlayersByUser = [];
        $uniqueCandidateIds = array_values(array_unique($candidatePlayerIds));
        if ($uniqueCandidateIds !== [])
        {
            $playerRows = DB::table('players')
                ->whereIn('id', $uniqueCandidateIds)
                ->get(['id', 'user_id']);

            foreach ($playerRows as $row)
            {
                $validPlayersByUser[(int) $row->user_id][(int) $row->id] = true;
            }
        }

        /** @var array<int, array<string, true>> $existingKeysByMatch */
        $existingKeysByMatch = [];
        $existingRows = DB::table('match_players')
            ->whereIn('match_id', $matchIds)
            ->get(['match_id', 'player_id', 'team', 'player_name']);

        foreach ($existingRows as $existing)
        {
            $mId = (int) $existing->match_id;
            $pId = $existing->player_id !== null ? (int) $existing->player_id : null;
            $key = self::dedupKey($pId, (string) $existing->team, (string) $existing->player_name);
            $existingKeysByMatch[$mId][$key] = true;
        }

        $now = now();
        $rowsToInsert = [];

        foreach ($matches as $match)
        {
            $matchId = (int) $match->id;
            $userId = (int) $match->user_id;
            $decoded = $decodedByMatchId[$matchId] ?? null;

            if (! is_array($decoded))
            {
                continue;
            }

            $createdAt = $match->created_at ?? $now;
            $updatedAt = $match->updated_at ?? $createdAt;

            foreach (['home', 'away'] as $side)
            {
                $players = $decoded[$side]['players'] ?? null;
                if (! is_array($players))
                {
                    continue;
                }

                foreach ($players as $player)
                {
                    if (! is_array($player))
                    {
                        continue;
                    }

                    $rawId = isset($player['id']) && is_numeric($player['id']) ? (int) $player['id'] : null;
                    $playerId = ($rawId !== null && isset($validPlayersByUser[$userId][$rawId]))
                        ? $rawId
                        : null;

                    $rawName = isset($player['name']) && is_string($player['name'])
                        ? trim($player['name'])
                        : '';
                    $playerName = $rawName !== '' ? $rawName : self::REMOVED_PLAYER_LABEL;

                    $key = self::dedupKey($playerId, $side, $playerName);
                    if (isset($existingKeysByMatch[$matchId][$key]))
                    {
                        continue;
                    }

                    $existingKeysByMatch[$matchId][$key] = true;

                    $rowsToInsert[] = [
                        'match_id' => $matchId,
                        'user_id' => $userId,
                        'player_id' => $playerId,
                        'player_name' => $playerName,
                        'team' => $side,
                        'present' => isset($player['present']) ? (bool) $player['present'] : true,
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt,
                    ];
                }
            }
        }

        if ($rowsToInsert === [])
        {
            return 0;
        }

        return DB::table('match_players')->insertOrIgnore($rowsToInsert);
    }

    private static function dedupKey(?int $playerId, string $side, string $playerName): string
    {
        if ($playerId !== null)
        {
            return "id:{$playerId}";
        }

        return 'null:'.$side.':'.mb_strtolower(trim($playerName));
    }
}
