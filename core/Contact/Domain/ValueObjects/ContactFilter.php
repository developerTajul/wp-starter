<?php

declare(strict_types=1);

namespace Core\Contact\Domain\ValueObjects;

use Core\Contact\Domain\Rules\ContactRules;
use Core\Contact\Domain\Exceptions\InvalidContactException;

/**
 * Filter criteria the repository uses to narrow down the contact list.
 * Validates itself, so the criteria reaching Infrastructure are always usable.
 */
final readonly class ContactFilter
{
    /**
     * @param string|null $search  Free-text term matched against name, email and phone.
     * @param int         $perPage
     *
     * @throws InvalidContactException When the page size falls outside its bounds.
     */
    public function __construct(
        public ?string $search = null,
        public int $perPage = ContactRules::DEFAULT_PER_PAGE,
    ) {
        if ($perPage < ContactRules::MIN_PER_PAGE || $perPage > ContactRules::MAX_PER_PAGE) {
            throw InvalidContactException::invalidPerPage($perPage);
        }
    }
}
