<?php

declare(strict_types=1);

namespace Core\Pipeline\Application\DTOs\Requests;

/**
 * Carries validated delivery-layer input INTO the Service for an update,
 * including the stages the pipeline is made of. Immutable.
 *
 * A pipeline edit replaces the whole pipeline: the name, the default flag and
 * the complete stage set all arrive together, so there is no partial-update
 * state to represent and no clear flag beside any field.
 */
final readonly class UpdatePipelineDTO
{
    /**
     * @param int    $id
     * @param string $name
     * @param bool   $isDefault
     * @param array<int, StorePipelineStageDTO> $stages
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $isDefault,
        public array $stages,
    ) {}
}
