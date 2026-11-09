<?php

declare(strict_types=1);

namespace Core\Lead\Application\Mappers;

use Core\Lead\Application\Contracts\LeadMapperContract;
use Core\Lead\Application\DTOs\Requests\LeadCreateDTO;
use Core\Lead\Application\DTOs\Requests\LeadFilterDTO;
use Core\Lead\Application\DTOs\Requests\LeadNoteCreateDTO;
use Core\Lead\Application\DTOs\Requests\LeadOwnerAssignDTO;
use Core\Lead\Application\DTOs\Requests\LeadUpdateDTO;
use Core\Lead\Domain\Enums\LeadStatus;
use Core\Lead\Domain\Rules\LeadRules;
use Core\Shared\Domain\Contracts\TranslatorContract;
use InvalidArgumentException;

/**
 * Maps validated request input to Lead DTOs.
 *
 * @implements \Core\Lead\Application\Contracts\LeadMapperContract
 */
final class LeadMapper implements LeadMapperContract
{
    private const DEFAULT_PER_PAGE = 15;

    /**
     * @param TranslatorContract $translator
     */
    public function __construct(
        private readonly TranslatorContract $translator,
    ) {}

    /**
     * Builds a LeadCreateDTO from validated input.
     *
     * @param array $validatedData
     *
     * @return LeadCreateDTO
     */
    public function mapToCreateDTO(array $validatedData): LeadCreateDTO
    {
        return new LeadCreateDTO(
            name: (string) $validatedData['name'],
            email: $validatedData['email'] ?? null,
            phone: $validatedData['phone'] ?? null,
            companyName: $validatedData['company_name'] ?? null,
            status: LeadStatus::tryFrom((string) ($validatedData['status'] ?? 'new')) ?? LeadStatus::NEW,
            source: (string) ($validatedData['source'] ?? 'website'),
        );
    }

    /**
     * Builds a LeadUpdateDTO from validated input and the id being edited.
     *
     * A field the form submitted as an empty string is the user asking to
     * empty that column, so it becomes a null value with its clear flag set.
     * A field the payload does not carry at all is absent, and the entity
     * keeps the stored value. The name is required by the validator, so it is
     * read as text and never cleared.
     *
     * @param int   $id
     * @param array $validatedData
     *
     * @return LeadUpdateDTO
     */
    public function mapToUpdateDTO(int $id, array $validatedData): LeadUpdateDTO
    {
        return new LeadUpdateDTO(
            id: $id,
            name: (string) ($validatedData['name'] ?? ''),
            email: $this->toOptionalText($validatedData, 'email'),
            clearEmail: $this->isClearing($validatedData, 'email'),
            phone: $this->toOptionalText($validatedData, 'phone'),
            clearPhone: $this->isClearing($validatedData, 'phone'),
            companyName: $this->toOptionalText($validatedData, 'company_name'),
            clearCompanyName: $this->isClearing($validatedData, 'company_name'),
            source: (string) ($validatedData['source'] ?? ''),
        );
    }

    /**
     * Builds a LeadOwnerAssignDTO for handing a lead to a user, or releasing it.
     *
     * A missing or blank assigned_to is the form asking for a release, which
     * the DTO carries as a null owner. Anything else is read as the new owner's
     * id; the request has already checked that the user exists.
     *
     * @param int   $leadId
     * @param array $validatedData
     * @param int   $requestedBy
     *
     * @return LeadOwnerAssignDTO
     */
    public function mapToOwnerAssignDTO(int $leadId, array $validatedData, int $requestedBy): LeadOwnerAssignDTO
    {
        $assignedTo = $validatedData['assigned_to'] ?? null;

        return new LeadOwnerAssignDTO(
            leadId: $leadId,
            assignedTo: $assignedTo === null ? null : (int) $assignedTo,
            requestedBy: $requestedBy,
        );
    }

    /**
     * Builds a LeadNoteCreateDTO from validated input and the lead being noted.
     *
     * @param int   $leadId
     * @param array $validatedData
     *
     * @return LeadNoteCreateDTO
     */
    public function mapToNoteCreateDTO(int $leadId, array $validatedData): LeadNoteCreateDTO
    {
        return new LeadNoteCreateDTO(
            leadId: $leadId,
            body: (string) ($validatedData['body'] ?? ''),
        );
    }

    /**
     * Reads an optional text field. An absent key means the caller did not
     * send the field, so null leaves the stored value alone; an empty string
     * is a value the caller sent, and the clear flag beside it says so.
     *
     * @param array  $validatedData
     * @param string $field
     *
     * @return string|null
     */
    private function toOptionalText(array $validatedData, string $field): ?string
    {
        if (! array_key_exists($field, $validatedData)) {
            return null;
        }

        $value = $validatedData[$field];

        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Reports whether the caller asked for a field to be emptied, which is a
     * value that was sent and came back blank.
     *
     * @param array  $validatedData
     * @param string $field
     *
     * @return bool
     */
    private function isClearing(array $validatedData, string $field): bool
    {
        if (! array_key_exists($field, $validatedData)) {
            return false;
        }

        $value = $validatedData[$field];

        return $value === null || trim((string) $value) === '';
    }

    /**
     * Builds a LeadFilterDTO from query input, rejecting unrecognised values.
     *
     * @param array $queryParams
     *
     * @return LeadFilterDTO
     *
     * @throws InvalidArgumentException When a supplied value is not usable.
     */
    public function mapToFilterDTO(array $queryParams): LeadFilterDTO
    {
        return new LeadFilterDTO(
            status: $this->toEnum(LeadStatus::class, $queryParams['status'] ?? null, 'status'),
            source: $this->toText($queryParams['source'] ?? null, 'source'),
            search: $this->toText($queryParams['search'] ?? null, 'search'),
            perPage: $this->toPerPage($queryParams['per_page'] ?? self::DEFAULT_PER_PAGE),
        );
    }

    /**
     * Builds a LeadFilterDTO for user-facing screens, dropping and reporting
     * any value it cannot convert. The page size is cut to the module's bounds
     * instead of dropped, so a stale bookmark still renders a list.
     *
     * @param array $queryParams
     *
     * @return array{0: LeadFilterDTO, 1: array<int, string>}
     */
    public function mapToFilterDTOLenient(array $queryParams): array
    {
        $ignored = [];

        return [
            new LeadFilterDTO(
                status: $this->toEnumOrNull(LeadStatus::class, $queryParams['status'] ?? null, 'status', $ignored),
                source: $this->toTextOrNull($queryParams['source'] ?? null, 'source', $ignored),
                search: $this->toTextOrNull($queryParams['search'] ?? null, 'search', $ignored),
                perPage: $this->toPerPageClamped($queryParams, $ignored),
            ),
            $ignored,
        ];
    }

    /**
     * Builds the status options for the list filter select.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function toStatusOptions(): array
    {
        return array_map(
            fn(LeadStatus $status) => [
                'value' => $status->value,
                'label' => $this->translator->translate($status->translationKey()),
            ],
            LeadStatus::cases(),
        );
    }

    /**
     * Reads the page size, cutting it to the bounds the domain filter accepts
     * so no caller has to know them (R12.2).
     *
     * A supplied number that is too large is cut to the maximum rather than
     * dropped: the list still renders, at the widest page the module allows. A
     * value that is not a number at all cannot be cut, so it is dropped and
     * reported. An absent value is not a dropped one, so it just takes the
     * default without being reported.
     *
     * @param array              $queryParams
     * @param array<int, string> $ignored Collects the field when dropped.
     *
     * @return int
     */
    private function toPerPageClamped(array $queryParams, array &$ignored): int
    {
        if (! array_key_exists('per_page', $queryParams) || $queryParams['per_page'] === null || $queryParams['per_page'] === '') {
            return LeadRules::DEFAULT_PER_PAGE;
        }

        if (! is_numeric($queryParams['per_page'])) {
            $ignored[] = 'per_page';

            return LeadRules::DEFAULT_PER_PAGE;
        }

        return LeadRules::clampPerPage((int) $queryParams['per_page']);
    }

    /**
     * @param class-string<\BackedEnum> $enum
     * @param mixed                     $value
     * @param string                    $field
     * @param array<int, string>        $ignored Collects the field when dropped.
     *
     * @return \BackedEnum|null
     */
    private function toEnumOrNull(
        string $enum,
        mixed $value,
        string $field,
        array &$ignored
    ): ?\BackedEnum {
        try {
            return $this->toEnum($enum, $value, $field);
        } catch (InvalidArgumentException) {
            $ignored[] = $field;

            return null;
        }
    }

    /**
     * @param mixed              $value
     * @param string             $field
     * @param array<int, string> $ignored Collects the field when dropped.
     *
     * @return string|null
     */
    private function toTextOrNull(mixed $value, string $field, array &$ignored): ?string
    {
        try {
            return $this->toText($value, $field);
        } catch (InvalidArgumentException) {
            $ignored[] = $field;

            return null;
        }
    }

    /**
     * @param class-string<\BackedEnum> $enum
     * @param mixed                     $value
     * @param string                    $field
     *
     * @return \BackedEnum|null
     *
     * @throws InvalidArgumentException When the value is not a case of the enum.
     */
    private function toEnum(string $enum, mixed $value, string $field): ?\BackedEnum
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

        $case = $enum::tryFrom($value);

        if ($case === null) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported value [%s] for filter [%s].',
                $value,
                $field,
            ));
        }

        return $case;
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
     * Converts a page size, rejecting anything outside the bounds the domain
     * filter accepts. The bound is checked here so an out-of-range value fails
     * as bad input rather than as a domain exception inside the Service.
     *
     * @param mixed $perPage
     *
     * @return int
     *
     * @throws InvalidArgumentException When the value is not a usable page size.
     */
    private function toPerPage(mixed $perPage): int
    {
        if (! is_numeric($perPage)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported value [%s] for filter [per_page].',
                is_scalar($perPage) ? (string) $perPage : get_debug_type($perPage),
            ));
        }

        $pageSize = (int) $perPage;

        if ($pageSize < LeadRules::MIN_PER_PAGE || $pageSize > LeadRules::MAX_PER_PAGE) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported value [%s] for filter [per_page]. Page size must be between %d and %d.',
                (string) $pageSize,
                LeadRules::MIN_PER_PAGE,
                LeadRules::MAX_PER_PAGE,
            ));
        }

        return $pageSize;
    }
}
