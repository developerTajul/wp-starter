<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Contracts;

use Core\Lead\Domain\Entities\LeadEntity;

/**
 * Write side of the lead persistence port. It carries the writes and the one
 * read that cannot be separated from a write: the row lock taken just before a
 * status change. Every other read belongs to LeadQueryContract.
 */
interface LeadRepositoryContract
{
    /**
     * Creates a new lead and returns it as a Domain Entity.
     *
     * @param LeadEntity $lead A lead created through its named constructor.
     *
     * @return LeadEntity The stored lead.
     */
    public function create(LeadEntity $lead): LeadEntity;

    /**
     * Reads a lead for update, holding a row lock until the transaction ends.
     *
     * Taken inside the transaction that is about to change the status, so a
     * second request cannot read the same row and decide from stale state.
     *
     * @param int $id
     *
     * @return LeadEntity|null
     */
    public function findByIdForUpdate(int $id): ?LeadEntity;

    /**
     * Persists the lead's current status and returns the stored row, so the
     * caller maps post-write values instead of the ones it loaded.
     *
     * @param LeadEntity $lead
     *
     * @return LeadEntity
     */
    public function updateStatus(LeadEntity $lead): LeadEntity;

    /**
     * Persists the lead's current owner and returns the stored row, so the
     * caller maps post-write values instead of the ones it loaded.
     *
     * Its own method rather than a widened updateStatus(): the two writes touch
     * different columns and are guarded by different rules, and one UPDATE that
     * carried both would let a status change silently overwrite an owner.
     *
     * @param LeadEntity $lead
     *
     * @return LeadEntity
     */
    public function updateOwner(LeadEntity $lead): LeadEntity;

    /**
     * Persists the lead's own editable fields and returns the stored row.
     *
     * Writes the four columns an edit form owns - name, email, phone and
     * company_name - and returns the row as it now stands, so the caller maps
     * the stored timestamps rather than the ones it loaded. Status and owner
     * are not touched: each has its own update method and its own guard.
     *
     * @param LeadEntity $lead
     *
     * @return LeadEntity
     */
    public function update(LeadEntity $lead): LeadEntity;

    /**
     * Removes the lead.
     *
     * The row itself goes: the leads table has no soft-delete column, so there
     * is nothing to mark. Rows that hang off the lead through a cascade
     * foreign key go with it.
     *
     * @param int $id
     *
     * @return void
     */
    public function delete(int $id): void;
}
