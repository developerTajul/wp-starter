<?php

declare(strict_types=1);

namespace Core\Pipeline\Application\DTOs\Responses;

/**
 * Shape returned to the delivery layer for one stage of a pipeline detail read.
 */
final readonly class PipelineStageDetailResponseDTO
{
    /**
     * @param int    $id
     * @param string $name
     * @param int    $order_position
     * @param int    $probability
     * @param string|null $created_at
     * @param string|null $updated_at
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $order_position,
        public int $probability,
        public ?string $created_at,
        public ?string $updated_at,
    ) {}
}
