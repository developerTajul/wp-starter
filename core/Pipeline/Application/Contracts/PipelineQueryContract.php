<?php

declare(strict_types=1);

namespace Core\Pipeline\Application\Contracts;

use Core\Pipeline\Domain\DataObjects\KanbanBoardData;
use Core\Pipeline\Domain\DataObjects\PipelineDetailData;

/**
 * PORT: read side for pipelines.
 */
interface PipelineQueryContract
{
    /**
     * Returns one pipeline together with the stages it is made of.
     *
     * @param int $id
     *
     * @return PipelineDetailData|null Null when no pipeline carries the id.
     */
    public function findDetailById(int $id): ?PipelineDetailData;

    /**
     * Returns every pipeline together with the stages it is made of. The list
     * screen prints each pipeline's stages, so both halves travel together and
     * a row never needs a second read.
     *
     * @return array<int, PipelineDetailData> Empty when no pipeline exists.
     */
    public function getAllWithStages(): array;

    /**
     * Returns one pipeline with its stages and the deals sitting in each, as the
     * Kanban board shows them.
     *
     * @param int $id
     *
     * @return KanbanBoardData|null Null when no pipeline carries the id.
     */
    public function findKanbanBoardById(int $id): ?KanbanBoardData;
}
