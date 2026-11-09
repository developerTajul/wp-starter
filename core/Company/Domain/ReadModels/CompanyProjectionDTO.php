<?php

declare(strict_types=1);

namespace Core\Company\Domain\ReadModels;

/**
 * A company row as the list read returns it: the columns the list shows and
 * nothing else. Hydrating a full Entity for a table row is what this read
 * model exists to avoid.
 */
final readonly class CompanyProjectionDTO
{
    /**
     * @param int         $id
     * @param string      $name
     * @param string|null $website
     * @param string|null $industry
     * @param string|null $phone
     * @param string|null $address
     * @param string|null $createdAt
     * @param string|null $updatedAt
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
    ) {}
}
