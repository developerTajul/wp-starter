<?php

declare(strict_types=1);

namespace Core\Contact\Application\Mappers;

use Core\Contact\Application\Contracts\ContactMapperContract;
use Core\Contact\Application\DTOs\Requests\ContactFilterDTO;
use Core\Contact\Application\DTOs\Requests\StoreContactDTO;
use Core\Contact\Application\DTOs\Requests\UpdateContactDTO;
use Core\Contact\Domain\Rules\ContactRules;
use Core\Contact\Domain\ValueObjects\CompanyLink;
use InvalidArgumentException;

/**
 * Maps validated request input to Contact DTOs.
 *
 * @implements ContactMapperContract
 */
final class ContactMapper implements ContactMapperContract
{
    /**
     * Builds a StoreContactDTO from validated input.
     *
     * The company linkage travels as one unit: the title and the primary flag
     * only carry meaning against a company, so they are dropped when no
     * company_id was supplied rather than handed down as loose values.
     *
     * @param array $validatedData
     *
     * @return StoreContactDTO
     *
     * @throws InvalidArgumentException When a value cannot be converted.
     */
    public function mapToStoreDTO(array $validatedData): StoreContactDTO
    {
        $companyId = $this->toNullableId($validatedData['company_id'] ?? null, 'company_id');

        return new StoreContactDTO(
            name: trim((string) $validatedData['name']),
            email: trim((string) $validatedData['email']),
            phone: $this->toNullableString($validatedData['phone'] ?? null),
            companyId: $companyId,
            jobTitle: $companyId === null
                ? null
                : $this->toNullableString($validatedData['job_title'] ?? null),
            isPrimary: $companyId !== null && $this->toBoolean($validatedData['is_primary'] ?? false),
        );
    }

    /**
     * Builds an UpdateContactDTO from validated input.
     *
     * An absent key leaves the field unchanged. A supplied null or blank string
     * clears it, which is what a client gets by submitting an emptied form
     * field, and the clear flag is what carries that intent down to the entity.
     * Any other value replaces the field. Only phone can be cleared this way;
     * a contact cannot be left without a name or an email.
     *
     * @param int   $contactId     The contact ID from the route.
     * @param array $validatedData The validated input data from the delivery layer.
     *
     * @return UpdateContactDTO
     */
    public function mapToUpdateDTO(int $contactId, array $validatedData): UpdateContactDTO
    {
        // Only a key the client actually sent can clear a field. An empty or
        // null submission is that request; an omitted key leaves the stored
        // value alone.
        $clearsName = $this->isSubmittedAndBlank($validatedData, 'name');
        $clearsEmail = $this->isSubmittedAndBlank($validatedData, 'email');
        $clearsPhone = $this->isSubmittedAndBlank($validatedData, 'phone');

        $updateContactDTO = new UpdateContactDTO(
            id: $contactId,
            // A cleared field and its flag travel together: the entity reads the
            // flag, and the null keeps the payload self-consistent against the
            // "replaced and cleared at once" contradiction. An empty string
            // needs the explicit null, since it is blank enough to clear but
            // still a value the DTO would otherwise carry.
            name: $clearsName ? null : $this->toReplacedText($validatedData, 'name'),
            email: $clearsEmail ? null : $this->toReplacedText($validatedData, 'email'),
            phone: $clearsPhone ? null : $this->toNullableString($validatedData['phone'] ?? null),
            clearPhone: $clearsPhone,
        );

        // An absent company_id leaves the stored link alone, so an update that
        // does not mention the company never unlinks the contact. A submitted
        // blank means "detach", and anything else names the company to attach.
        if (! array_key_exists('company_id', $validatedData)) {
            return $updateContactDTO;
        }

        if ($this->isBlank($validatedData['company_id'])) {
            return $this->withDetachedCompany($updateContactDTO);
        }

        return $updateContactDTO->withCompanyLink(CompanyLink::create(
            companyId: $this->toNullableId($validatedData['company_id'], 'company_id'),
            jobTitle: $this->toNullableString($validatedData['job_title'] ?? null),
            isPrimary: $this->toBoolean($validatedData['is_primary'] ?? false),
        ));
    }

    /**
     * Returns a copy asking to detach the contact from its company.
     *
     * @param UpdateContactDTO $updateContactDTO
     *
     * @return UpdateContactDTO
     */
    private function withDetachedCompany(UpdateContactDTO $updateContactDTO): UpdateContactDTO
    {
        return new UpdateContactDTO(
            id: $updateContactDTO->id,
            name: $updateContactDTO->name,
            email: $updateContactDTO->email,
            phone: $updateContactDTO->phone,
            clearPhone: $updateContactDTO->clearPhone,
            companyLink: null,
            clearCompany: true,
        );
    }

    /**
     * Builds a ContactFilterDTO from query input, rejecting unrecognised values.
     *
     * @param array $queryParams
     *
     * @return ContactFilterDTO
     *
     * @throws InvalidArgumentException When a supplied value is not usable.
     */
    public function mapToFilterDTO(array $queryParams): ContactFilterDTO
    {
        return new ContactFilterDTO(
            search: $this->toText($queryParams['search'] ?? null, 'search'),
            perPage: $this->toPerPage($queryParams['per_page'] ?? ContactRules::DEFAULT_PER_PAGE),
        );
    }

    /**
     * Converts a free-text filter value. A blank value means "no filter"
     * rather than an empty match.
     *
     * @param mixed  $value
     * @param string $field
     *
     * @return string|null
     *
     * @throws InvalidArgumentException When the value is not text.
     */
    private function toText(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported value [%s] for filter [%s].',
                is_scalar($value) ? (string) $value : get_debug_type($value),
                $field,
            ));
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Converts a page size into one the list can serve. The bounds are the
     * domain's to decide, so the value is handed to ContactRules, which clamps
     * it (05 R12.2): an out-of-range value is not bad input, and every channel
     * gets the same usable page size, so a hand-edited URL can never make the
     * list read more rows than the module allows.
     *
     * A non-numeric value never reaches the rule: it is read here as "none
     * asked for", which is a delivery concern, not a domain one.
     *
     * @param mixed $perPage
     *
     * @return int
     */
    private function toPerPage(mixed $perPage): int
    {
        if (! is_numeric($perPage)) {
            return ContactRules::DEFAULT_PER_PAGE;
        }

        return ContactRules::clampPerPage((int) $perPage);
    }

    /**
     * Reads a field that can be replaced but never blanked. A blank value is
     * read as null here; the caller pairs it with a clear flag, and the entity
     * refuses to leave the contact without a name or an email. The presence of
     * the key must be checked with isSubmittedAndBlank() before relying on a
     * null to mean "cleared", since an omitted field reads as null too.
     *
     * @param array  $validatedData
     * @param string $field
     *
     * @return string|null
     */
    private function toReplacedText(array $validatedData, string $field): ?string
    {
        $value = $this->toNullableString($validatedData[$field] ?? null);

        if ($value === null) {
            return null;
        }

        return trim($value);
    }

    /**
     * Whether the client actually sent this field, and sent it blank. An
     * omitted key is not a request to clear anything.
     *
     * @param array  $validatedData
     * @param string $field
     *
     * @return bool
     */
    private function isSubmittedAndBlank(array $validatedData, string $field): bool
    {
        if (! array_key_exists($field, $validatedData)) {
            return false;
        }

        return $this->isBlank($validatedData[$field]);
    }

    /**
     * Whether a supplied value is an explicit request to clear the field. Only
     * a key the client actually sent counts: an omitted field stays unchanged.
     *
     * @param mixed $value
     *
     * @return bool
     */
    private function isBlank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Converts an optional company ID.
     *
     * @param mixed  $value
     * @param string $field
     *
     * @return int|null
     *
     * @throws InvalidArgumentException When the value is not a usable ID.
     */
    private function toNullableId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (int) $value <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported value [%s] for field [%s].',
                is_scalar($value) ? (string) $value : get_debug_type($value),
                $field,
            ));
        }

        return (int) $value;
    }

    /**
     * Narrows a supplied value to a string, reading an absent or empty one as
     * null so the DTO does not carry an empty string where it means "not set".
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private function toNullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * Converts a request value to a real boolean. A cast would read the
     * string "false" as true.
     *
     * @param mixed $value
     *
     * @return bool
     */
    private function toBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
