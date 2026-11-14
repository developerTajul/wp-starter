<?php

declare(strict_types=1);

namespace Core\Company\Application\DTOs\Requests;

/**
 * Carries the company list criteria into the Service. Built by CompanyMapper.
 */
final readonly class CompanyFilterDTO
{
    /**
     * @param int $perPage
     * @param int $page      1-based page number.
     */
    public function __construct(
        public int $perPage = 15,
        public int $page = 1,
    ) {}
}
