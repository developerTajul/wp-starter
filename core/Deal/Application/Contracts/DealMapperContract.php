<?php

declare(strict_types=1);

namespace Core\Deal\Application\Contracts;

use Core\Deal\Application\DTOs\Requests\DealFilterDTO;
use Core\Deal\Application\DTOs\Requests\StoreDealDTO;
use Core\Deal\Application\DTOs\Requests\UpdateDealDTO;

/**
 * PORT: maps validated delivery-layer input to deal DTOs.
 */
interface DealMapperContract
{
    /**
     * Builds a StoreDealDTO from validated input.
     *
     * @param array $validatedData
     *
     * @return StoreDealDTO
     */
    public function mapToStoreDTO(array $validatedData): StoreDealDTO;

    /**
     * Builds an UpdateDealDTO from validated input. The stage's name is passed
     * in beside the payload because it is read from storage, not sent by the
     * client.
     *
     * @param int         $id            The deal ID from the route.
     * @param array       $validatedData The validated input data from the delivery layer.
     * @param string|null $stageName     The name of the stage the deal moves to.
     *
     * @return UpdateDealDTO
     */
    public function mapToUpdateDTO(int $id, array $validatedData, ?string $stageName = null): UpdateDealDTO;

    /**
     * Builds a DealFilterDTO from query input.
     *
     * @param array $queryParams
     *
     * @return DealFilterDTO
     */
    public function mapToFilterDTO(array $queryParams): DealFilterDTO;
}
