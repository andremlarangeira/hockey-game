<?php

namespace App\Data;

use JsonSerializable;

class RosterTeam implements JsonSerializable
{
    /** @param list<array{id: int, name: string, jersey_number: int, level: float|numeric-string, photo_url?: string|null}> $players */
    public function __construct(
        public readonly string $name,
        public readonly string $color,
        public readonly array $players,
    ) {}

    public function jsonSerialize(): mixed
    {
        return ['name' => $this->name, 'color' => $this->color, 'players' => $this->players];
    }
}
