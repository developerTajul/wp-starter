<?php

declare(strict_types=1);

namespace Core\Pipeline\Application\DTOs\Responses;

/**
 * Shape returned to the delivery layer for the Kanban board.
 */
final readonly class KanbanBoardResponseDTO
{
    /**
     * @param int    $id
     * @param string $name
     * @param bool   $is_default
     * @param array<int, KanbanStageResponseDTO> $stages
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $is_default,
        public array $stages,
    ) {}
}
