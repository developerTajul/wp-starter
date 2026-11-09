<?php

declare(strict_types=1);

namespace Core\Contact\Application\Contracts;

use Core\Contact\Application\DTOs\Requests\ContactFilterDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Handles contact read use cases.
 */
interface ContactQueryServiceContract
{
    /**
     * Returns the paginated contact list matching the given criteria.
     *
     * @param ContactFilterDTO $contactFilterDTO
     *
     * @return Result Contains the PaginatedResult of ContactResponseDTO on success.
     */
    public function getPaginatedContacts(ContactFilterDTO $contactFilterDTO): Result;

    /**
     * Returns one contact's full profile.
     *
     * @param string $id
     *
     * @return Result Contains the ContactDetailResponseDTO on success.
     */
    public function getContactDetails(string $id): Result;
}
