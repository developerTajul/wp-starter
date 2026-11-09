<?php

declare(strict_types=1);

namespace Core\Pipeline\Application\Contracts;

use Core\Pipeline\Application\DTOs\Requests\StorePipelineDTO;
use Core\Pipeline\Application\DTOs\Requests\UpdatePipelineDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Handles pipeline write use cases.
 */
interface PipelineCommandServiceContract
{
    /**
     * Creates a pipeline together with the stages it is made of.
     *
     * @param StorePipelineDTO $storePipelineDTO
     *
     * @return Result Contains the PipelineResponseDTO on success.
     */
    public function createPipeline(StorePipelineDTO $storePipelineDTO): Result;

    /**
     * Applies an edit to an existing pipeline and its stages.
     *
     * @param UpdatePipelineDTO $updatePipelineDTO
     *
     * @return Result Contains the PipelineResponseDTO on success.
     */
    public function update(UpdatePipelineDTO $updatePipelineDTO): Result;
}
