<?php

declare(strict_types=1);

namespace Core\Pipeline\Application\Contracts;

use Core\Shared\Application\DTOs\Result;

/**
 * Handles pipeline read use cases.
 */
interface PipelineQueryServiceContract
{
    /**
     * Returns every pipeline with the stages it is made of.
     *
     * @return Result Contains the list of PipelineResponseDTO on success.
     */
    public function getPipelines(): Result;

    /**
     * Returns one pipeline together with the stages it is made of.
     *
     * @param string $id
     *
     * @return Result Contains the PipelineDetailResponseDTO on success.
     */
    public function getPipelineDetails(string $id): Result;

    /**
     * Returns the Kanban board for one pipeline: its stages, and the deals
     * sitting in each.
     *
     * @param string $id
     *
     * @return Result Contains the KanbanBoardResponseDTO on success.
     */
    public function getKanbanBoard(string $id): Result;
}
