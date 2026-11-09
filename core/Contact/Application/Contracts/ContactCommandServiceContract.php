<?php

declare(strict_types=1);

namespace Core\Contact\Application\Contracts;

use Core\Contact\Application\DTOs\Requests\StoreContactDTO;
use Core\Contact\Application\DTOs\Requests\UpdateContactDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * Handles contact write use cases.
 */
interface ContactCommandServiceContract
{
    /**
     * Creates a contact.
     *
     * @param StoreContactDTO $storeContactDTO
     *
     * @return Result Contains the ContactResponseDTO on success.
     */
    public function createContact(StoreContactDTO $storeContactDTO): Result;

    /**
     * Updates an existing contact.
     *
     * @param UpdateContactDTO $updateContactDTO
     *
     * @return Result Contains the ContactResponseDTO on success.
     */
    public function updateContact(UpdateContactDTO $updateContactDTO): Result;

    /**
     * Deletes an existing contact.
     *
     * @param int $id
     *
     * @return Result
     */
    public function deleteContact(int $contactId): Result;
}
