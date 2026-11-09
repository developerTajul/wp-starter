<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Responses;

use Core\Lead\Domain\ReadModels\LeadTimelineProjectionDTO;

/**
 * Shape the lead detail page's activity timeline is handed in.
 *
 * Carries the entries together with the total, so the widget can say how many
 * rows were left off the end of a bounded read without asking again.
 */
final readonly class LeadTimelineResponseDTO
{
    /**
     * @param array<int, LeadTimelineProjectionDTO> $entries The timeline rows, newest first.
     * @param int                                   $total   How many activities the lead carries in all.
     * @param int                                   $limit   How many rows were asked for.
     */
    public function __construct(
        public array $entries,
        public int $total,
        public int $limit,
    ) {}
}
