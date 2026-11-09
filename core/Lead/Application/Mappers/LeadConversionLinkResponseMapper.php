<?php

declare(strict_types=1);

namespace Core\Lead\Application\Mappers;

use Core\Lead\Application\DTOs\Responses\LeadConversionLinkResponseDTO;
use Core\Lead\Domain\Entities\LeadConversionLinkEntity;
use DateTimeInterface;

/**
 * Maps a stored conversion link to LeadConversionLinkResponseDTO.
 */
final class LeadConversionLinkResponseMapper
{
    /**
     * Builds the response from the link the repository stored.
     *
     * @param LeadConversionLinkEntity $link
     *
     * @return LeadConversionLinkResponseDTO
     */
    public function toResponseDTO(LeadConversionLinkEntity $link): LeadConversionLinkResponseDTO
    {
        return new LeadConversionLinkResponseDTO(
            id: $link->id,
            lead_id: $link->leadId,
            contact_id: $link->contactId,
            company_id: $link->companyId,
            deal_id: $link->dealId,
            converted_at: $link->convertedAt->format(DateTimeInterface::ATOM),
        );
    }
}