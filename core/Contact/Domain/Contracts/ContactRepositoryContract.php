<?php

declare(strict_types=1);

namespace Core\Contact\Domain\Contracts;

use Core\Contact\Domain\Entities\ContactEntity;

/**
 * PORT: persistence for contacts.
 */
interface ContactRepositoryContract
{
    /**
     * Reads a contact while holding a row lock, so a concurrent write cannot
     * slip between the read and the save.
     *
     * The lock is a storage concern: callers ask for a contact they mean to
     * change, and the adapter decides how that is made safe.
     *
     * @param int $contactId
     *
     * @return ContactEntity|null Null when no contact carries the id.
     */
    public function findByIdForUpdate(int $contactId): ?ContactEntity;

    /**
     * Inserts the contact and attaches the companies it carries.
     *
     * The contact row and its links belong to one use case, so the adapter
     * runs them inside one transaction and a failure leaves neither behind.
     *
     * @param ContactEntity $contact
     *
     * @return ContactEntity The stored contact, rebuilt from storage.
     */
    public function create(ContactEntity $contact): ContactEntity;

    /**
     * Persists changes to an existing contact, including the companies it now
     * carries.
     *
     * The contact row and its links belong to one use case, so the adapter runs
     * them inside one transaction and a failure leaves neither behind.
     *
     * @param ContactEntity $contact
     *
     * @return ContactEntity The stored contact, rebuilt from storage.
     */
    public function update(ContactEntity $contact): ContactEntity;

    /**
     * Deletes a contact.
     *
     * @param int $contactId
     *
     * @return void
     */
    public function delete(int $contactId): void;
}
