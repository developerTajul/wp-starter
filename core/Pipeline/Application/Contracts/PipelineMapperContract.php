<?php

declare(strict_types=1);

namespace Core\Pipeline\Application\Contracts;

use Core\Pipeline\Application\DTOs\Requests\StorePipelineDTO;
use Core\Pipeline\Application\DTOs\Requests\UpdatePipelineDTO;

/**
 * PORT: maps validated delivery-layer input to pipeline DTOs.
 */
interface PipelineMapperContract
{
    /**
     * Builds a StorePipelineDTO from validated input.
     *
     * @param array $validatedData
     *
     * @return StorePipelineDTO
     */
    public function mapToStoreDTO(array $validatedData): StorePipelineDTO;

    /**
     * Builds an UpdatePipelineDTO from validated input.
     *
     * @param int   $id            The pipeline ID from the route.
     * @param array $validatedData The validated input data from the delivery layer.
     *
     * @return UpdatePipelineDTO
     */
    public function mapToUpdateDTO(int $id, array $validatedData): UpdatePipelineDTO;
}
