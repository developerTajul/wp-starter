<?php

declare(strict_types=1);

namespace Core\Pipeline\Domain\DataObjects;

use Core\Pipeline\Domain\ReadModels\PipelineProjectionDTO;

/**
 * Read model for the pipeline detail view. Pairs the pipeline's own columns
 * with the stage rows it was read with, both already flattened to plain values
 * so the read adapter never has to hydrate a domain Entity.
 *
 * The stage rows carry the keys of the columns they came from; the
 * PipelineResponseMapper turns them into the nested response DTOs.
 */
final readonly class PipelineDetailData
{
    /**
     * @param PipelineProjectionDTO            $pipeline
     * @param array<int, array<string, mixed>> $stages
     */
    public function __construct(
        public PipelineProjectionDTO $pipeline,
        public array $stages = [],
    ) {}
}
