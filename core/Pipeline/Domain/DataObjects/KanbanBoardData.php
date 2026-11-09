<?php

declare(strict_types=1);

namespace Core\Pipeline\Domain\DataObjects;

use Core\Pipeline\Domain\ReadModels\PipelineProjectionDTO;

/**
 * Read model for the Kanban board: the pipeline and its stages, each carrying
 * the deals that sit in it.
 */
final readonly class KanbanBoardData
{
    /**
     * @param PipelineProjectionDTO       $pipeline
     * @param array<int, KanbanStageData> $stages
     */
    public function __construct(
        public PipelineProjectionDTO $pipeline,
        public array $stages = [],
    ) {}
}
