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
     * Every nullable column is cast through {@see toNullableString()} rather
     * than passed through. A driver does not return a VARCHAR as a string: a
     * digits-only value such as a phone number comes back as a PHP int, and
     * `CompanyEntity::reconstitute()` declares `?string`, so under
     * `strict_types=1` the int is a TypeError rather than a coercion. Casting at
     * the boundary keeps the entity's signature honest and means the adapter,
     * not the domain, absorbs the driver's typing.
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
     * An empty string reads as absent, matching the entity's own reading: the
     * create and edit forms submit `''` for a field left blank, and `null` is
     * what the entity stores for one.
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
