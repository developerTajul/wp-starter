<?php

declare(strict_types=1);

namespace Core\Deal\Infrastructure\Mappers;

use Core\Deal\Domain\Entities\DealEntity;

/**
 * Maps model arrays to DealEntity and to the deals table's columns.
 */
final class DealEntityMapper
{
    /**
     * Builds a pure domain entity from a model array.
     *
     * The amount arrives as a decimal string, so it keeps the digits the
     * column stored instead of going through a float.
     *
     * @param array $data
     *
     * @return DealEntity
     */
    public function toEntity(array $data): DealEntity
    {
        return DealEntity::reconstitute(
            id: (int) $data['id'],
            title: (string) $data['title'],
            companyId: $this->toNullableInt($data['company_id'] ?? null),
            contactId: $this->toNullableInt($data['contact_id'] ?? null),
            amount: (string) $data['amount'],
            currency: (string) $data['currency'],
            pipelineId: (int) $data['pipeline_id'],
            dealStageId: (int) $data['deal_stage_id'],
            expectedCloseDate: $this->toNullableString($data['expected_close_date'] ?? null),
            actualCloseDate: $this->toNullableString($data['actual_close_date'] ?? null),
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
        );
    }

    /**
     * Flattens the entity's own state onto the deals table's columns.
     *
     * @param DealEntity $deal
     *
     * @return array<string, mixed>
     */
    public function toPersistenceArray(DealEntity $deal): array
    {
        return [
            'title' => $deal->getTitle(),
            'company_id' => $deal->getCompanyId(),
            'contact_id' => $deal->getContactId(),
            'amount' => $deal->getAmount(),
            'currency' => $deal->getCurrency(),
            'pipeline_id' => $deal->getPipelineId(),
            'deal_stage_id' => $deal->getDealStageId(),
            'expected_close_date' => $deal->getExpectedCloseDate(),
            'actual_close_date' => $deal->getActualCloseDate(),
        ];
    }

    /**
     * The nullable foreign keys come back as null or a numeric string
     * depending on the driver, so both are narrowed to int.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * Narrows a stored date to its string form, reading an absent one as null.
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
