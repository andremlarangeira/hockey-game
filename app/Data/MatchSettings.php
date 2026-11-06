<?php

namespace App\Data;

use JsonSerializable;

/** @phpstan-import-type SoundSettings from Types */
class MatchSettings implements JsonSerializable
{
    /** @param SoundSettings $sounds */
    public function __construct(
        public readonly int $minutes,
        public readonly int $periods,
        public readonly int $restSeconds,
        public readonly int $countdownSeconds,
        public readonly array $sounds = [],
    ) {}

    /** @param array{minutes: int, periods: int, rest_seconds: int, countdown_seconds: int, sounds?: SoundSettings} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['minutes'], $data['periods'], $data['rest_seconds'], $data['countdown_seconds'], $data['sounds'] ?? []);
    }

    public static function fromJson(string $json): self
    {
        /** @var array{minutes: int, periods: int, rest_seconds: int, countdown_seconds: int, sounds?: SoundSettings} $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }

    public function jsonSerialize(): mixed
    {
        return [
            'minutes' => $this->minutes, 'periods' => $this->periods,
            'rest_seconds' => $this->restSeconds, 'countdown_seconds' => $this->countdownSeconds,
            'sounds' => $this->sounds,
        ];
    }
}
