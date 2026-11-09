<?php

declare(strict_types=1);

namespace Core\Company\Application\Contracts;

use Core\Company\Application\DTOs\Responses\CompanyDetailResponseDTO;
use Core\Company\Application\DTOs\Responses\CompanyEditResponseDTO;
use Core\Company\Application\DTOs\Responses\CompanyResponseDTO;
use Core\Company\Domain\DataObjects\CompanyDetailData;
use Core\Company\Domain\Entities\CompanyEntity;
use Core\Company\Domain\ReadModels\CompanyProjectionDTO;

/**
 * PORT: turns a company read result into the response shape a channel sends.
 */
interface CompanyResponseMapperContract
{
    /**
     * Builds the response DTO from the company entity. Used by the write path,
     * whose result is a persisted entity.
     *
     * @param CompanyEntity $company
     *
     * @return CompanyResponseDTO
     */
    public function toResponseDTO(CompanyEntity $company): CompanyResponseDTO;

    /**
     * Builds the list response DTO from a projected company row. The list read
     * never hydrates an entity, so the read side maps from the projection.
     *
     * @param CompanyProjectionDTO $company
     *
     * @return CompanyResponseDTO
     */
    public function toResponseDTOFromProjection(CompanyProjectionDTO $company): CompanyResponseDTO;

    /**
     * Builds the edit-form DTO from the company entity.
     *
     * @param CompanyEntity $company
     *
     * @return CompanyEditResponseDTO
     */
    public function toEditResponseDTO(CompanyEntity $company): CompanyEditResponseDTO;

    /**
     * Builds the 360-degree profile DTO from the detail read model.
     *
     * @param CompanyDetailData $companyDetail
     *
     * @return CompanyDetailResponseDTO
     */
    public function toDetailResponseDTO(CompanyDetailData $companyDetail): CompanyDetailResponseDTO;
}
