<?php

declare(strict_types=1);

namespace Core\Contact\Infrastructure\Mappers;

use Core\Contact\Domain\Entities\ContactEntity;
use Core\Contact\Domain\ValueObjects\CompanyLink;

/**
 * Maps model arrays to ContactEntity and to the contacts table's columns.
 */
final class ContactEntityMapper
{
    /**
     * Builds a pure domain entity from a model array.
     *
     * @param array                $data     The contact row's columns.
     * @param list<array<string, mixed>> $companyRows The attached companies, as read from the pivot.
     *
     * @return ContactEntity
     */
    public function toEntity(array $data, array $companyRows = []): ContactEntity
    {
        return ContactEntity::reconstitute(
            id: (int) $data['id'],
            name: (string) $data['name'],
            email: (string) $data['email'],
            phone: $data['phone'] ?? null,
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
            companyLinks: array_map($this->toCompanyLink(...), $companyRows),
        );
    }

    /**
     * Rebuilds one pivot row as the link value object the entity carries.
     *
     * @param array<string, mixed> $company
     *
     * @return CompanyLink
     */
    public function toCompanyLink(array $company): CompanyLink
    {
        return CompanyLink::reconstitute(
            companyId: (int) $company['id'],
            jobTitle: $company['job_title'] ?? null,
            isPrimary: (bool) ($company['is_primary'] ?? false),
            companyName: $company['name'] ?? null,
            companyPhone: $company['phone'] ?? null,
            companyWebsite: $company['website'] ?? null,
            companyIndustry: $company['industry'] ?? null,
        );
    }

    /**
     * Flattens a link back to the pivot's columns.
     *
     * @param CompanyLink $link
     *
     * @return array<string, mixed>
     */
    public function toPivotArray(CompanyLink $link): array
    {
        return [
            'job_title' => $link->jobTitle,
            'is_primary' => $link->isPrimary,
        ];
    }

    /**
     * Flattens the entity's own state onto the contacts table's columns.
     *
     * @param ContactEntity $contact
     *
     * @return array<string, mixed>
     */
    public function toPersistenceArray(ContactEntity $contact): array
    {
        return [
            'name' => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
        ];
    }
}
