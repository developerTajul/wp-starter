<?php

declare(strict_types=1);

namespace Core\Company\Domain\ValueObjects;

use Core\Company\Domain\CompanyRules;
use Core\Company\Domain\Exceptions\InvalidCompanyException;

/**
 * Filter criteria the repository uses to narrow down the company list.
 * Validates itself, so the criteria reaching Infrastructure are always usable.
 */
final readonly class CompanyFilter
{
    /**
     * @param string|null $search  Free-text term matched against name and industry.
     * @param int         $perPage
     *
     * @throws InvalidCompanyException When the page size falls outside its bounds.
     */
    public function __construct(
        public ?string $search = null,
        public int $perPage = 15,
    ) {
        if ($perPage < CompanyRules::MIN_PER_PAGE || $perPage > CompanyRules::MAX_PER_PAGE) {
            throw InvalidCompanyException::invalidPerPage($perPage);
        }
    }
}
