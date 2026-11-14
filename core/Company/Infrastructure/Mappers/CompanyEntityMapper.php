<?php

declare(strict_types=1);

namespace Core\Company\Infrastructure\Mappers;

use Core\Company\Domain\Entities\CompanyEntity;

/**
 * Maps model arrays to CompanyEntity and to the company table's columns.
 */
final class CompanyEntityMapper
{
    /**
     * Builds a pure domain entity from a database row.
     *
     * @param array $data
     *
     * @return CompanyEntity
     */
    public function toEntity(array $data): CompanyEntity
    {
        return CompanyEntity::reconstitute(
            id: (int) $data['id'],
            name: (string) $data['name'],
            website: $this->toNullableString($data['website'] ?? null),
            industry: $this->toNullableString($data['industry'] ?? null),
            phone: $this->toNullableString($data['phone'] ?? null),
            address: $this->toNullableString($data['address'] ?? null),
            createdAt: $this->toNullableString($data['created_at'] ?? null),
            updatedAt: $this->toNullableString($data['updated_at'] ?? null),
        );
    }

    /**
     * A column value as a nullable string.
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
     * Flattens the entity's own state onto the company table's columns.
     *
     * @param CompanyEntity $company
     *
     * @return array<string, mixed>
     */
    public function toPersistenceArray(CompanyEntity $company): array
    {
        return [
            'name' => $company->name,
            'website' => $company->website,
            'industry' => $company->industry,
            'phone' => $company->phone,
            'address' => $company->address,
        ];
    }
}
