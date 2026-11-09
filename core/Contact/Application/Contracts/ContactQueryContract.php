<?php

declare(strict_types=1);

namespace Core\Contact\Application\Contracts;

use Core\Contact\Domain\DataObjects\ContactDetailData;
use Core\Contact\Domain\ValueObjects\ContactFilter;
use Core\Shared\Domain\DataObjects\PaginatedResult;

/**
 * Read side of the contact persistence port.
 */
interface ContactQueryContract
{
    /**
     * Returns the contacts matching the given filter criteria.
     *
     * @param ContactFilter $filter
     *
     * @return PaginatedResult Items are ContactProjectionDTO instances.
     */
    public function getFilteredContacts(ContactFilter $filter): PaginatedResult;

    /**
     * Returns one contact together with its companies and activities.
     *
     * @param int $contactId
     *
     * @return ContactDetailData|null Null when no contact carries the id.
     */
    public function findDetailById(int $contactId): ?ContactDetailData;
}
