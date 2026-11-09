<?php

declare(strict_types=1);

namespace Core\Company\Application\DTOs\Requests;

/**
 * Carries the company list criteria into the Service. Built by CompanyMapper.
 */
final readonly class CompanyFilterDTO
{
    /**
     * @param string|null $search
     * @param int         $perPage
     */
    public function __construct(
        public ?string $search = null,
        public int $perPage = 15,
    ) {}
}
