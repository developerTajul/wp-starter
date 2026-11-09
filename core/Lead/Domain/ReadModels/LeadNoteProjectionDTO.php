<?php

declare(strict_types=1);

namespace Core\Lead\Domain\ReadModels;

use DateTimeImmutable;

/**
 * Read model for a lead's quick notes. Carries what the widget draws, without
 * hydrating the entity the write path needs.
 */
final readonly class LeadNoteProjectionDTO
{
    public function __construct(
        public int $id,
        public int $leadId,
        public string $body,
        public bool $isPinned,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}
}