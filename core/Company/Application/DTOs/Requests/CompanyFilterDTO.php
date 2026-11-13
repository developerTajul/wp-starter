<?php

declare(strict_types=1);

namespace Core\Company\Application\DTOs\Requests;

/**
 * Carries the company list criteria into the Service. Built by CompanyMapper.
 */
final readonly class CompanyFilterDTO
{
    public function __construct(public int $perPage = 15) {}
}
