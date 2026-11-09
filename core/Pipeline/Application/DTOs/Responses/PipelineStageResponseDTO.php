<?php

declare(strict_types=1);

namespace Core\Pipeline\Application\DTOs\Responses;

/**
 * Shape returned to the delivery layer for one pipeline stage.
 */
final readonly class PipelineStageResponseDTO
{
    /**
     * @param int    $id
     * @param string $name
     * @param int    $order_position
     * @param int    $probability
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $order_position,
        public int $probability,
    ) {}
}
