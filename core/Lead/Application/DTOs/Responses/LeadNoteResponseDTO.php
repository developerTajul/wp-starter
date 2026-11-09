<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Responses;

/**
 * Shape a quick note is handed to the delivery layer in.
 */
final readonly class LeadNoteResponseDTO
{
    /**
     * @param int|null    $id        Null before the note is stored.
     * @param int         $leadId
     * @param string      $body
     * @param bool        $isPinned
     * @param string|null $createdAt ISO-8601, or null before the note is stored.
     * @param string|null $updatedAt ISO-8601, or null before the note is stored.
     */
    public function __construct(
        public ?int $id,
        public int $leadId,
        public string $body,
        public bool $isPinned,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
