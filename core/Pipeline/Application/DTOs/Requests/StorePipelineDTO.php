<?php

declare(strict_types=1);

namespace Core\Pipeline\Application\DTOs\Requests;

/**
 * Carries validated delivery-layer input INTO the Service, including the
 * stages the pipeline is made of. Immutable.
 */
final readonly class StorePipelineDTO
{
    /**
     * @param string                  $name
     * @param bool                    $isDefault
     * @param array<int, StorePipelineStageDTO> $stages
     */
    public function __construct(
        public string $name,
        public bool $isDefault,
        public array $stages,
    ) {}
}
