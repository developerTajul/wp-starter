<?php

declare(strict_types=1);

namespace Core\Contact\Application\DTOs\Requests;

use Core\Contact\Domain\ValueObjects\CompanyLink;

/**
 * Carries validated delivery-layer input INTO the Service. Immutable.
 * Built EXCLUSIVELY by the module's Request Mapper - no factory methods here.
 * A derived field arrives through its with*() clone instead.
 *
 * A partial update has three states per field: absent (leave the stored value
 * alone), an explicit null (clear it), or a value (replace it). A plain
 * nullable field collapses the first two into one null, so a clear flag
 * carries the "drop this" intent alongside it.
 *
 * The company link follows the same shape: a null $companyLink with a false
 * $clearCompany means the stored link is left alone, so an update that does
 * not mention the company never unlinks the contact by accident. Attaching
 * and detaching both need a flag, because a link is a whole value carrying a
 * company, a job title and a primary flag - a nullable field can say "no
 * company" but cannot say "a different company, with the rest dropped".
 */
final readonly class UpdateContactDTO
{
    /**
     * @param int              $id
     * @param string|null      $name
     * @param string|null      $email
     * @param string|null      $phone
     * @param bool             $clearPhone   Drop the stored phone number.
     * @param CompanyLink|null $companyLink  The link to attach, or null to leave the stored one alone.
     * @param bool             $clearCompany Detach the contact from its company.
     */
    public function __construct(
        public int $id,
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
        public bool $clearPhone = false,
        public ?CompanyLink $companyLink = null,
        public bool $clearCompany = false,
    ) {}

    /**
     * Returns a copy carrying the company link to attach. Deriving the link
     * needs the Domain Value Object, so the Mapper builds it and hands it over
     * through this clone rather than re-passing every property inline.
     *
     * @param CompanyLink $companyLink
     *
     * @return self
     */
    public function withCompanyLink(CompanyLink $companyLink): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            email: $this->email,
            phone: $this->phone,
            clearPhone: $this->clearPhone,
            companyLink: $companyLink,
            clearCompany: $this->clearCompany,
        );
    }
}
