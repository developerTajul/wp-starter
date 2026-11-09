<?php

declare(strict_types=1);

namespace Core\Contact\Application\Mappers;

use Core\Contact\Application\DTOs\Responses\CompanyLinkResponseDTO;
use Core\Contact\Application\DTOs\Responses\ContactActivityResponseDTO;
use Core\Contact\Application\DTOs\Responses\ContactDetailResponseDTO;
use Core\Contact\Application\DTOs\Responses\ContactResponseDTO;
use Core\Contact\Domain\DataObjects\ContactDetailData;
use Core\Contact\Domain\Entities\ContactEntity;
use Core\Contact\Domain\ReadModels\ContactProjectionDTO;
use Core\Contact\Domain\ValueObjects\CompanyLink;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Maps a stored contact to ContactResponseDTO.
 */
final class ContactResponseMapper
{
    /**
     * Builds the response DTO from the stored contact and the companies it
     * carries. Timestamps are stored as UTC wall-clock strings and leave on the
     * same ISO-8601 wire format the other modules use, so a client parses every
     * module's dates alike.
     *
     * @param ContactEntity $contact
     *
     * @return ContactResponseDTO
     */
    public function toResponseDTO(ContactEntity $contact): ContactResponseDTO
    {
        return new ContactResponseDTO(
            id: $contact->id,
            name: $contact->name,
            email: $contact->email,
            phone: $contact->phone,
            created_at: $this->toAtom($contact->createdAt),
            updated_at: $this->toAtom($contact->updatedAt),
            companies: array_map($this->toCompanyLinkDTO(...), $contact->companyLinks),
        );
    }

    /**
     * Builds the list response DTO from a projected row. The list read never
     * hydrates an entity, so the same wire shape is built from the projection.
     *
     * @param ContactProjectionDTO $contact
     *
     * @return ContactResponseDTO
     */
    public function toResponseDTOFromProjection(ContactProjectionDTO $contact): ContactResponseDTO
    {
        return new ContactResponseDTO(
            id: $contact->id,
            name: $contact->name,
            email: $contact->email,
            phone: $contact->phone,
            created_at: $this->toAtom($contact->createdAt),
            updated_at: $this->toAtom($contact->updatedAt),
            companies: array_map($this->toCompanyLinkDTO(...), $contact->companies),
        );
    }

    /**
     * Builds the 360-degree profile DTO: the contact's own fields plus its
     * companies and activities. This is the one place the flattened rows the
     * repository returns become nested DTOs.
     *
     * @param ContactDetailData $contactDetail
     *
     * @return ContactDetailResponseDTO
     */
    public function toDetailResponseDTO(ContactDetailData $contactDetail): ContactDetailResponseDTO
    {
        $contact = $contactDetail->contact;

        return new ContactDetailResponseDTO(
            id: $contact->id,
            name: $contact->name,
            email: $contact->email,
            phone: $contact->phone,
            created_at: $this->toAtom($contact->createdAt),
            updated_at: $this->toAtom($contact->updatedAt),
            companies: array_map($this->toCompanyLinkDTO(...), $contact->companyLinks),
            activities: array_map($this->toActivityDTO(...), $contactDetail->activities),
        );
    }

    /**
     * @param array<string, mixed> $activity
     *
     * @return ContactActivityResponseDTO
     */
    private function toActivityDTO(array $activity): ContactActivityResponseDTO
    {
        return new ContactActivityResponseDTO(
            id: $activity['id'],
            type: (string) $activity['type'],
            subject: (string) $activity['subject'],
            description: $activity['description'] ?? null,
            due_date: $this->toAtom($activity['due_date'] ?? null),
            completed_at: $this->toAtom($activity['completed_at'] ?? null),
            user_name: $activity['user_name'] ?? null,
        );
    }

    /**
     * @param CompanyLink $company
     *
     * @return CompanyLinkResponseDTO
     */
    private function toCompanyLinkDTO(CompanyLink $company): CompanyLinkResponseDTO
    {
        return new CompanyLinkResponseDTO(
            id: $company->companyId,
            name: (string) $company->companyName,
            phone: $company->companyPhone,
            website: $company->companyWebsite,
            industry: $company->companyIndustry,
            job_title: $company->jobTitle,
            is_primary: $company->isPrimary,
        );
    }

    /**
     * Formats a stored UTC timestamp as ISO-8601. Parsing it in the application
     * timezone would shift the instant on every round trip.
     *
     * @param string|null $value
     *
     * @return string|null
     */
    private function toAtom(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);
    }
}
