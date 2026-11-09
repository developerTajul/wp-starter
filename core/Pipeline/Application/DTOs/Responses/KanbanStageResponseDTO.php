<?php

declare(strict_types=1);

namespace Core\Pipeline\Application\DTOs\Responses;

/**
 * Shape returned to the delivery layer for one Kanban column.
 */
final readonly class KanbanStageResponseDTO
{
    /**
     * @param int    $id
     * @param string $name
     * @param int    $order_position
     * @param int    $probability
     * @param int    $deals_count
     * @param float  $total_value
     * @param array<int, KanbanDealResponseDTO> $deals
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $order_position,
        public int $probability,
        public int $deals_count,
        public float $total_value,
        public array $deals,
    ) {}
}
