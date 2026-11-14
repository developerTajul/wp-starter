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
     * The page to read, 1-based.
     *
     * Clamped rather than rejected: a page below the first is someone editing a
     * URL, and the ceiling cannot be settled here at all because it depends on
     * how many rows match, which only the count read knows. The repository
     * clamps the upper bound.
     */
    public int $page;

    /**
     * @param int $perPage
     * @param int $page    1-based; anything lower is read as the first page.
     *
     * @throws InvalidCompanyException When the page size falls outside its bounds.
     */
    public function __construct(
        public int $perPage = 15,
        int $page = 1,
    ) {
        if ($perPage < CompanyRules::MIN_PER_PAGE || $perPage > CompanyRules::MAX_PER_PAGE) {
            throw InvalidCompanyException::invalidPerPage($perPage);
        }

        $this->page = max(CompanyRules::MIN_PAGE, $page);
    }
}
