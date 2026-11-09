<?php

declare(strict_types=1);

namespace Core\Contact\Domain\ReadModels;

use Core\Contact\Domain\ValueObjects\CompanyLink;

/**
 * A contact row as the list read returns it: the columns the list shows and
 * nothing else. Hydrating a full Entity for a table row is what this read
 * model exists to avoid.
 *
 * The attached companies travel as link value objects, because the list shows
 * their names beside the contact.
 */
final readonly class ContactProjectionDTO
{
    /**
     * @param int|null          $id
     * @param string            $name
     * @param string            $email
     * @param string|null       $phone
     * @param string|null       $createdAt
     * @param string|null       $updatedAt
     * @param list<CompanyLink> $companies
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public string $email,
        public ?string $phone,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public array $companies = [],
    ) {}
}
