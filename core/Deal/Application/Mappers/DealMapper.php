<?php

declare(strict_types=1);

namespace Core\Deal\Application\Mappers;

use Core\Deal\Application\Contracts\DealMapperContract;
use Core\Deal\Application\DTOs\Requests\DealFilterDTO;
use Core\Deal\Application\DTOs\Requests\StoreDealDTO;
use Core\Deal\Application\DTOs\Requests\UpdateDealDTO;
use Core\Deal\Domain\DealRules;
use InvalidArgumentException;

/**
 * Maps validated request input to Deal DTOs.
 *
 * @implements DealMapperContract
 */
final class DealMapper implements DealMapperContract
{
    private const DEFAULT_AMOUNT = '0.00';

    private const DEFAULT_CURRENCY = 'BDT';

    /**
     * Builds a StoreDealDTO from validated input.
     *
     * @param array $validatedData
     *
     * @return StoreDealDTO
     *
     * @throws InvalidArgumentException When a value cannot be converted.
     */
    public function mapToStoreDTO(array $validatedData): StoreDealDTO
    {
        return new StoreDealDTO(
            title: trim((string) $validatedData['title']),
            pipelineId: $this->toId($validatedData['pipeline_id'] ?? null, 'pipeline_id'),
            dealStageId: $this->toId($validatedData['deal_stage_id'] ?? null, 'deal_stage_id'),
            companyId: $this->toNullableId($validatedData['company_id'] ?? null, 'company_id'),
            contactId: $this->toNullableId($validatedData['contact_id'] ?? null, 'contact_id'),
            amount: $this->toAmount($validatedData['amount'] ?? null),
            currency: $this->toCurrency($validatedData['currency'] ?? null),
            expectedCloseDate: $this->toNullableString($validatedData['expected_close_date'] ?? null),
            actualCloseDate: $this->toNullableString($validatedData['actual_close_date'] ?? null),
        );
    }

    /**
     * Builds an UpdateDealDTO from validated input.
     *
     * An absent key leaves the field unchanged. A supplied value replaces it,
     * which is what a client gets by submitting a filled form field. Only the
     * keys the client actually sent are carried, so an update that does not
     * mention the company never unlinks the deal.
     *
     * The close date is not read from the payload: it follows the stage.
     *
     * @param int         $id            The deal ID from the route.
     * @param array       $validatedData The validated input data from the delivery layer.
     * @param string|null $stageName     The name of the stage the deal moves to.
     *
     * @return UpdateDealDTO
     *
     * @throws InvalidArgumentException When a value cannot be converted.
     */
    public function mapToUpdateDTO(int $id, array $validatedData, ?string $stageName = null): UpdateDealDTO
    {
        $dealStageId = $this->toSubmittedId($validatedData, 'deal_stage_id');

        return new UpdateDealDTO(
            id: $id,
            title: $this->toSubmittedText($validatedData, 'title'),
            companyId: $this->toSubmittedOptionalId($validatedData, 'company_id'),
            unlinkCompany: $this->isUnlinking($validatedData, 'company_id'),
            contactId: $this->toSubmittedOptionalId($validatedData, 'contact_id'),
            unlinkContact: $this->isUnlinking($validatedData, 'contact_id'),
            amount: $this->toSubmittedAmount($validatedData),
            currency: $this->toSubmittedCurrency($validatedData),
            pipelineId: $this->toSubmittedId($validatedData, 'pipeline_id'),
            dealStageId: $dealStageId,
            // The name only means something beside the stage it names, so it is
            // dropped when the payload carries no stage. A caller that reads the
            // stage itself passes the name in.
            stageName: $dealStageId === null ? null : $stageName,
            expectedCloseDate: $this->toSubmittedDate($validatedData),
        );
    }

    /**
     * Builds a DealFilterDTO from query input, rejecting unrecognised values.
     *
     * @param array $queryParams
     *
     * @return DealFilterDTO
     *
     * @throws InvalidArgumentException When a supplied value is not usable.
     */
    public function mapToFilterDTO(array $queryParams): DealFilterDTO
    {
        return new DealFilterDTO(
            pipelineId: $this->toOptionalId($queryParams['pipeline_id'] ?? null, 'pipeline_id'),
            dealStageId: $this->toOptionalId($queryParams['deal_stage_id'] ?? null, 'deal_stage_id'),
            perPage: $this->toPerPage($queryParams['per_page'] ?? DealRules::DEFAULT_PER_PAGE),
        );
    }

    /**
     * Converts a required ID. The bounds the field allows are the entity's to
     * enforce, so this only turns the input into the type the DTO declares.
     *
     * @param mixed  $value
     * @param string $field
     *
     * @return int
     *
     * @throws InvalidArgumentException When the value is not a usable ID.
     */
    private function toId(mixed $value, string $field): int
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported value [%s] for field [%s].',
                is_scalar($value) ? (string) $value : get_debug_type($value),
                $field,
            ));
        }

        return (int) $value;
    }

    /**
     * Converts an ID that arrived as an update field. An absent key means "leave
     * the stored value alone", so it reads as null. Only a key the client
     * actually sent is converted, and a blank one is a client error rather than
     * a silent clear: no foreign key on a deal may be emptied.
     *
     * @param array  $validatedData
     * @param string $field
     *
     * @return int|null
     *
     * @throws InvalidArgumentException When the value is not a usable ID.
     */
    private function toSubmittedId(array $validatedData, string $field): ?int
    {
        if (! array_key_exists($field, $validatedData)) {
            return null;
        }

        if ($this->isBlank($validatedData[$field])) {
            throw new InvalidArgumentException(sprintf(
                'Field [%s] cannot be cleared, it needs a value.',
                $field,
            ));
        }

        return $this->toPositiveId($validatedData[$field], $field);
    }

    /**
     * Converts an optional link that arrived as an update field. The deal's
     * company and contact may each be absent, so a blank submission is the
     * client asking to unlink one rather than an error; a caller that never
     * meant to touch the field omits the key entirely, which reads as null and
     * leaves the stored value alone.
     *
     * @param array  $validatedData
     * @param string $field
     *
     * @return int|null
     *
     * @throws InvalidArgumentException When a supplied value is not a usable ID.
     */
    private function toSubmittedOptionalId(array $validatedData, string $field): ?int
    {
        if (! array_key_exists($field, $validatedData) || $this->isBlank($validatedData[$field])) {
            return null;
        }

        return $this->toPositiveId($validatedData[$field], $field);
    }

    /**
     * Whether an optional link arrived blank, which is the client asking to drop
     * it. A key that was never sent says nothing about the link, so it is not a
     * request to clear one.
     *
     * @param array  $validatedData
     * @param string $field
     *
     * @return bool
     */
    private function isUnlinking(array $validatedData, string $field): bool
    {
        return array_key_exists($field, $validatedData) && $this->isBlank($validatedData[$field]);
    }

    /**
     * Converts a value that must name an existing row.
     *
     * @param mixed  $value
     * @param string $field
     *
     * @return int
     *
     * @throws InvalidArgumentException When the value is not a usable ID.
     */
    private function toPositiveId(mixed $value, string $field): int
    {
        $id = $this->toId($value, $field);

        if ($id <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported value [%s] for field [%s].',
                (string) $id,
                $field,
            ));
        }

        return $id;
    }

    /**
     * Converts a text field that arrived as an update field. An absent key
     * leaves the stored value alone; a blank one is a client error, because a
     * deal cannot be left without a title.
     *
     * @param array  $validatedData
     * @param string $field
     *
     * @return string|null
     *
     * @throws InvalidArgumentException When the value is not usable text.
     */
    private function toSubmittedText(array $validatedData, string $field): ?string
    {
        if (! array_key_exists($field, $validatedData)) {
            return null;
        }

        if ($this->isBlank($validatedData[$field])) {
            throw new InvalidArgumentException(sprintf(
                'Field [%s] cannot be cleared, it needs a value.',
                $field,
            ));
        }

        $text = trim((string) $validatedData[$field]);

        // Whitespace is blank enough to clear but is not the empty string, so
        // it has to be caught here rather than by the isBlank() check above.
        if ($text === '') {
            throw new InvalidArgumentException(sprintf(
                'Field [%s] cannot be cleared, it needs a value.',
                $field,
            ));
        }

        return $text;
    }

    /**
     * Converts an amount that arrived as an update field.
     *
     * @param array $validatedData
     *
     * @return string|null
     *
     * @throws InvalidArgumentException When the value is not a number.
     */
    private function toSubmittedAmount(array $validatedData): ?string
    {
        return array_key_exists('amount', $validatedData)
            ? $this->toAmount($this->requireSubmitted('amount', $validatedData['amount']))
            : null;
    }

    /**
     * Converts a currency that arrived as an update field.
     *
     * @param array $validatedData
     *
     * @return string|null
     *
     * @throws InvalidArgumentException When the value is not text.
     */
    private function toSubmittedCurrency(array $validatedData): ?string
    {
        return array_key_exists('currency', $validatedData)
            ? $this->toCurrency($this->requireSubmitted('currency', $validatedData['currency']))
            : null;
    }

    /**
     * Refuses a value that was sent but left empty. An absent key leaves the
     * stored value alone, but a key the client sent empty is a client error: a
     * deal cannot be left without these values, and the payload was validated as
     * though it were replacing them.
     *
     * @param string $field
     * @param mixed  $value
     *
     * @return mixed The value, once it is known not to be empty.
     *
     * @throws InvalidArgumentException When the supplied value is empty.
     */
    private function requireSubmitted(string $field, mixed $value): mixed
    {
        if ($this->isBlank($value)) {
            throw new InvalidArgumentException(sprintf(
                'Field [%s] cannot be cleared, it needs a value.',
                $field,
            ));
        }

        return $value;
    }

    /**
     * Converts an expected close date that arrived as an update field. The
     * stored date is what the column holds, so a client that sends nothing
     * leaves it alone.
     *
     * @param array $validatedData
     *
     * @return string|null
     */
    private function toSubmittedDate(array $validatedData): ?string
    {
        if (! array_key_exists('expected_close_date', $validatedData)) {
            return null;
        }

        return $this->toNullableString($validatedData['expected_close_date']);
    }

    /**
     * Converts an optional ID. A blank value means "no filter" rather than a
     * filter matching nothing.
     *
     * @param mixed  $value
     * @param string $field
     *
     * @return int|null
     *
     * @throws InvalidArgumentException When the value is not a usable ID.
     */
    private function toOptionalId(mixed $value, string $field): ?int
    {
        if ($this->isBlank($value)) {
            return null;
        }

        if (! is_numeric($value) || (int) $value <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported value [%s] for filter [%s].',
                is_scalar($value) ? (string) $value : get_debug_type($value),
                $field,
            ));
        }

        return (int) $value;
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

        if ($pageSize < DealRules::MIN_PER_PAGE || $pageSize > DealRules::MAX_PER_PAGE) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported value [%s] for filter [per_page]. Page size must be between %d and %d.',
                (string) $pageSize,
                DealRules::MIN_PER_PAGE,
                DealRules::MAX_PER_PAGE,
            ));
        }

        return $pageSize;
    }

    /**
     * Whether a supplied value is an explicit request to clear the field.
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
     * Converts an optional ID, reading an absent or blank one as null.
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

        return $this->toId($value, $field);
    }

    /**
     * Converts an amount to the decimal string the column holds. Amounts are
     * money, so they travel as strings and never through a float cast.
     *
     * @param mixed $value
     *
     * @return string
     *
     * @throws InvalidArgumentException When the value is not a number.
     */
    private function toAmount(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_AMOUNT;
        }

        if (! is_numeric($value)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported value [%s] for field [amount].',
                is_scalar($value) ? (string) $value : get_debug_type($value),
                'amount',
            ));
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * Converts a currency code, reading an absent or blank one as the column
     * default. The code is upper-cased so "bdt" and "BDT" are one currency.
     *
     * @param mixed $value
     *
     * @return string
     *
     * @throws InvalidArgumentException When the value is not text.
     */
    private function toCurrency(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_CURRENCY;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Unsupported value for field [currency].');
        }

        return mb_strtoupper(trim($value));
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
}
