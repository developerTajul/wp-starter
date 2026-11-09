<?php

declare(strict_types=1);

namespace Core\Lead\Domain\ValueObjects;

use Core\Lead\Domain\Enums\LeadStatus;
use Core\Lead\Domain\Exceptions\InvalidLeadException;
use Core\Lead\Domain\Rules\LeadRules;

/**
 * Filter criteria the repository uses to narrow down the lead list.
 * Validates itself, so the criteria reaching Infrastructure are always usable.
 */
final readonly class LeadFilter
{
    /**
     * @param LeadStatus|null $status
     * @param string|null     $source
     * @param string|null     $search
     * @param int             $perPage
     *
     * @throws InvalidLeadException When the page size falls outside its bounds.
     */
    public function __construct(
        public ?LeadStatus $status = null,
        public ?string $source = null,
        public ?string $search = null,
        public int $perPage = 15,
    ) {
        if ($perPage < LeadRules::MIN_PER_PAGE || $perPage > LeadRules::MAX_PER_PAGE) {
            throw InvalidLeadException::invalidPerPage($perPage);
        }
    }
}
