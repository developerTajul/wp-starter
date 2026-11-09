<?php

declare(strict_types=1);

namespace Core\Lead\Application\Mappers;

use Core\Lead\Application\DTOs\Responses\LeadConversionResponseDTO;
use Core\Lead\Domain\Entities\LeadEntity;
use Core\Shared\Domain\Contracts\TranslatorContract;
use DateTimeImmutable;

/**
 * Maps a converted lead to LeadConversionResponseDTO.
 */
final class LeadConversionResponseMapper
{
    /**
     * @param TranslatorContract $translator
     */
    public function __construct(
        private readonly TranslatorContract $translator,
    ) {}

    /**
     * Builds the response from the lead the conversion stored.
     *
     * The conversion is stamped when it happens rather than read from the row,
     * so it is passed in beside the lead it belongs to.
     *
     * @param LeadEntity        $lead
     * @param DateTimeImmutable $convertedAt
     *
     * @return LeadConversionResponseDTO
     */
    public function toResponseDTO(LeadEntity $lead, DateTimeImmutable $convertedAt): LeadConversionResponseDTO
    {
        return new LeadConversionResponseDTO(
            id: (int) $lead->id,
            name: $lead->name,
            email: $lead->email,
            phone: $lead->phone,
            company_name: $lead->companyName,
            status: $lead->getStatus()->value,
            status_label: $this->translator->translate($lead->getStatus()->translationKey()),
            source: $lead->source,
            converted_at: $convertedAt->format('Y-m-d H:i:s'),
        );
    }
}