<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Requests;

use Core\Shared\Application\DTOs\WriteDTO;

/**
 * Carries validated lead update data from the delivery layer to the service.
 *
 * Every field is optional and null means "leave the stored value alone". The
 * lead's four editable columns - name, email, phone, company_name - are all
 * nullable in the database, so a caller that wants to clear one sends an empty
 * string rather than omitting it. The mapper turns that blank into null and
 * sets the matching clear flag, because null alone cannot say both "unchanged"
 * and "emptied".
 *
 * Status and assigned_to are deliberately absent. Both move through their own
 * guarded use cases - changeStatus() and assignOwner() - which enforce the
 * transition rules, so accepting them here would open a second way to write
 * them that skips the domain's own guards.
 */
final readonly class LeadUpdateDTO extends WriteDTO
{
    /**
     * @param int    $id
     * @param string $name          The lead's name; never blank.
     * @param string|null $email
     * @param bool   $clearEmail    Whether the caller asked for the email to be emptied.
     * @param string|null $phone
     * @param bool   $clearPhone    Whether the caller asked for the phone to be emptied.
     * @param string|null $companyName
     * @param bool   $clearCompanyName Whether the caller asked for the company to be emptied.
     * @param string $source
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $email = null,
        public bool $clearEmail = false,
        public ?string $phone = null,
        public bool $clearPhone = false,
        public ?string $companyName = null,
        public bool $clearCompanyName = false,
        public string $source = '',
    ) {}
}
