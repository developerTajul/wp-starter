<?php

declare(strict_types=1);

namespace Core\Lead\Infrastructure\Mappers;

use Core\Lead\Domain\Entities\LeadEntity;
use Core\Lead\Domain\Enums\LeadStatus;
use Core\Lead\Domain\ReadModels\LeadProjectionDTO;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Maps model arrays to LeadEntity and to the lead read model.
 */
final class LeadEntityMapper
{
    /**
     * Rebuilds a datetime from a stored value. Datetimes are persisted as UTC
     * wall-clock strings, so they are read back as UTC too: parsing them in the
     * application timezone would shift the instant on every round trip.
     *
     * @param mixed $value
     *
     * @return DateTimeImmutable|null
     */
    private function toDateTime(mixed $value): ?DateTimeImmutable
    {
        if (empty($value)) {
            return null;
        }

        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }

    /**
     * Reads an optional integer column. The driver hands ids back as strings,
     * so they are cast; an empty value means the column is null.
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
     * Builds a pure domain entity from a model array.
     *
     * @param array $data
     *
     * @return LeadEntity
     */
    public function toEntity(array $data): LeadEntity
    {
        return new LeadEntity(
            id: $this->toNullableInt($data['id'] ?? null),
            name: (string) $data['name'],
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            companyName: $data['company_name'] ?? null,
            status: LeadStatus::tryFrom((string) $data['status']) ?? LeadStatus::NEW,
            source: (string) ($data['source'] ?? 'website'),
            createdAt: (string) $data['created_at'],
            updatedAt: (string) $data['updated_at'],
            assignedTo: $this->toNullableInt($data['assigned_to'] ?? null),
        );
    }

    /**
     * Builds the read model the list and detail pages draw.
     *
     * @param array $data
     *
     * @return LeadProjectionDTO
     */
    public function toProjection(array $data): LeadProjectionDTO
    {
        return new LeadProjectionDTO(
            id: (int) $data['id'],
            name: (string) $data['name'],
            email: $data['email'] ?? null,
            phone: $data['phone'] ?? null,
            companyName: $data['company_name'] ?? null,
            status: LeadStatus::tryFrom((string) $data['status']) ?? LeadStatus::NEW,
            source: (string) ($data['source'] ?? 'website'),
            assignedTo: $this->toNullableInt($data['assigned_to'] ?? null),
            createdAt: $this->toDateTime($data['created_at'] ?? null),
            updatedAt: $this->toDateTime($data['updated_at'] ?? null),
        );
    }

    /**
     * Builds the columns a new lead is inserted with.
     *
     * @param LeadEntity $lead
     *
     * @return array<string, mixed>
     */
    public function toPersistenceArray(LeadEntity $lead): array
    {
        return [
            'name' => $lead->name,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'company_name' => $lead->companyName,
            'status' => $lead->getStatus()->value,
            'source' => $lead->source,
        ];
    }
}