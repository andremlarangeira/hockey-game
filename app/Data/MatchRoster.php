<?php

namespace App\Data;

use JsonSerializable;

class MatchRoster implements JsonSerializable
{
    public function __construct(
        public readonly RosterTeam $home,
        public readonly RosterTeam $away,
    ) {}

    /** @param array{home: array{name: string, color: string, players: list<array{id: int, name: string, jersey_number: int, level: float|numeric-string, photo_url?: string|null}>}, away: array{name: string, color: string, players: list<array{id: int, name: string, jersey_number: int, level: float|numeric-string, photo_url?: string|null}>}} $data */
    public static function fromArray(array $data): self
    {
        return new self(new RosterTeam(...$data['home']), new RosterTeam(...$data['away']));
    }

    public static function fromJson(string $json): self
    {
        /** @var array{home: array{name: string, color: string, players: list<array{id: int, name: string, jersey_number: int, level: float|numeric-string, photo_url?: string|null}>}, away: array{name: string, color: string, players: list<array{id: int, name: string, jersey_number: int, level: float|numeric-string, photo_url?: string|null}>}} $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }

    public function team(string $side): ?RosterTeam
    {
        return match ($side)
        {
            'home' => $this->home,
            'away' => $this->away,
            default => null,
        };
    }

    public function jsonSerialize(): mixed
    {
        return ['home' => $this->home, 'away' => $this->away];
    }
}
