<?php

declare(strict_types=1);

namespace Core\Deal\Application\Contracts;

use Core\Deal\Application\DTOs\Requests\StoreDealDTO;
use Core\Deal\Application\DTOs\Requests\UpdateDealDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Handles deal write use cases.
 */
interface DealCommandServiceContract
{
    /**
     * Creates a deal.
     *
     * @param StoreDealDTO $storeDealDTO
     *
     * @return Result Contains the DealResponseDTO on success.
     */
    public function createDeal(StoreDealDTO $storeDealDTO): Result;

    /**
     * Updates an existing deal.
     *
     * @param UpdateDealDTO $updateDealDTO
     *
     * @return Result Contains the DealResponseDTO on success.
     */
    public function updateDeal(UpdateDealDTO $updateDealDTO): Result;

    /**
     * Deletes an existing deal.
     *
     * @param int $id
     *
     * @return Result
     */
    public function deleteDeal(int $id): Result;
}
