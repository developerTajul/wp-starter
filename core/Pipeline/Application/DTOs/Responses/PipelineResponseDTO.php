<?php

declare(strict_types=1);

namespace Core\Pipeline\Application\DTOs\Responses;

/**
 * Shape returned to the delivery layer for a pipeline and its stages.
 */
final readonly class PipelineResponseDTO
{
    /**
     * @param int    $id
     * @param string $name
     * @param bool   $is_default
     * @param array<int, PipelineStageResponseDTO> $stages
     * @param string|null $created_at
     * @param string|null $updated_at
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $is_default,
        public array $stages,
        public ?string $created_at,
        public ?string $updated_at,
    ) {}
}
