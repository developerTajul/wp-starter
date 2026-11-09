<?php

declare(strict_types=1);

namespace Core\Company\Domain\DataObjects;

use Core\Company\Domain\ReadModels\CompanyActivityProjectionDTO;
use Core\Company\Domain\ReadModels\CompanyContactProjectionDTO;
use Core\Company\Domain\ReadModels\CompanyDealProjectionDTO;

/**
 * Read model for the company detail view. The company's own columns and the
 * rows of its related records, all as typed read models rather than loose
 * column arrays, so a renamed column fails in the repository.
 *
 * The company stays a flat projection rather than a CompanyEntity: the detail
 * read shows the values, it never calls the company's behaviour.
 *
 * The CompanyResponseMapper turns these into the nested response DTOs.
 */
final readonly class CompanyDetailData
{
    /**
     * @param int                                 $id
     * @param string                              $name
     * @param string|null                         $website
     * @param string|null                         $industry
     * @param string|null                         $phone
     * @param string|null                         $address
     * @param string|null                         $createdAt
     * @param string|null                         $updatedAt
     * @param list<CompanyContactProjectionDTO>   $contacts
     * @param list<CompanyDealProjectionDTO>      $deals
     * @param list<CompanyActivityProjectionDTO>  $activities
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $website,
        public ?string $industry,
        public ?string $phone,
        public ?string $address,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public array $contacts = [],
        public array $deals = [],
        public array $activities = [],
    ) {}
}
