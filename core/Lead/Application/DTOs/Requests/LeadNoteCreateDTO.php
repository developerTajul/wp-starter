<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Requests;

/**
 * Input for writing a quick note against a lead.
 */
final readonly class LeadNoteCreateDTO
{
    /**
     * @param int    $leadId
     * @param string $body
     */
    public function __construct(
        public int $leadId,
        public string $body,
    ) {}
}
