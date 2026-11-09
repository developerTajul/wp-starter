<?php

declare(strict_types=1);

namespace Core\Lead\Application\Contracts;

use Core\Lead\Domain\Entities\LeadEntity;
use Core\Lead\Domain\ReadModels\LeadProjectionDTO;
use Core\Lead\Domain\ValueObjects\LeadFilter;
use Core\Shared\Domain\DataObjects\PaginatedResult;

/**
 * Read side of the lead persistence port. Returns Application read models for
 * the list and detail reads, and Domain Entities for the lookups the write use
 * cases need.
 */
interface LeadQueryContract
{
    /**
     * Returns the leads matching the given filter criteria.
     *
     * @param LeadFilter $filter
     *
     * @return PaginatedResult Items are LeadProjectionDTO instances.
     */
    public function getFilteredLeads(LeadFilter $filter): PaginatedResult;

    /**
     * Finds a lead by its ID.
     *
     * @param int $id
     *
     * @return LeadProjectionDTO|null
     */
    public function findById(int $id): ?LeadProjectionDTO;

    /**
     * Reports whether a lead with the given id is stored.
     *
     * The question "does this lead exist" is a pure read, so it is answered
     * here rather than by loading the whole lead: callers that only guard a
     * write against a missing parent need the answer, not the entity.
     *
     * @param int $id
     *
     * @return bool
     */
    public function exists(int $id): bool;

    /**
     * Finds a lead by its ID as a Domain Entity.
     *
     * For use cases that need the lead's own rules rather than a read model -
     * the status and conversion writes both load a lead, ask the entity whether
     * the move is allowed, and then persist. The entity-returning lookup lives
     * on the read port because it is a pure read; it is not separable from a
     * write it protects, which is what keeps it off the write port.
     *
     * @param int $id
     *
     * @return LeadEntity|null Null when no lead carries the id.
     */
    public function findEntityById(int $id): ?LeadEntity;

    /**
     * Finds a lead by email as a Domain Entity.
     *
     * The email is nullable because the column is: a lead that arrived without
     * one has no email to look up. A null email matches nothing and returns
     * null rather than being an error - the caller is asking "does a lead with
     * this email already exist", and for no email the answer is no.
     *
     * @param string|null $email
     *
     * @return LeadEntity|null Null when no lead matches, or when no email was given.
     */
    public function findEntityByEmail(?string $email): ?LeadEntity;

    /**
     * Finds a lead by phone as a Domain Entity.
     *
     * The phone is nullable because the column is: a lead that arrived without
     * one has no phone to look up. A null phone matches nothing and returns
     * null rather than being an error - the caller is asking "does a lead with
     * this phone already exist", and for no phone the answer is no.
     *
     * @param string|null $phone
     *
     * @return LeadEntity|null Null when no lead matches, or when no phone was given.
     */
    public function findEntityByPhone(?string $phone): ?LeadEntity;
}
