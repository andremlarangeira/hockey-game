<?php

namespace App\Championships\Data;

use App\Championships\Enums\TeamSide;

final readonly class GoalCommand
{
    public ?int $firstAssistId;

    public ?int $secondAssistId;

    public bool $isOvertime;

    /** @var list<int> */
    public array $assistIds;

    /**
     * @param  list<int|null>|null  $assistIds
     */
    public function __construct(
        public TeamSide $side,
        public int $scorerId,
        ?int $firstAssistId = null,
        ?int $secondAssistId = null,
        public int $period = 1,
        bool $isOvertime = false,
        public int $elapsedSeconds = 0,
        public int $remainingSeconds = 0,
        ?array $assistIds = null,
        ?bool $overtime = null,
    ) {
        if ($assistIds !== null)
        {
            $normalized = array_values(array_map(
                static fn (mixed $id): int => (int) $id,
                array_filter($assistIds, static fn (mixed $id): bool => $id !== null && $id !== ''),
            ));

            $this->assistIds = $normalized;
            $this->firstAssistId = $normalized[0] ?? null;
            $this->secondAssistId = $normalized[1] ?? null;
        }
        else
        {
            $normalized = [];
            if ($firstAssistId !== null)
            {
                $normalized[] = $firstAssistId;
            }
            if ($secondAssistId !== null)
            {
                $normalized[] = $secondAssistId;
            }

            $this->assistIds = $normalized;
            $this->firstAssistId = $firstAssistId;
            $this->secondAssistId = $secondAssistId;
        }

        $this->isOvertime = $overtime ?? $isOvertime;
    }
}
