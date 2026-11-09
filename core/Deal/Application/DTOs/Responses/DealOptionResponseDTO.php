<?php

declare(strict_types=1);

namespace Core\Deal\Application\DTOs\Responses;

/**
 * One row a deal form picker offers, carrying only what a picker shows.
 */
final readonly class DealOptionResponseDTO
{
    /**
     * @param int         $id
     * @param string      $label
     * @param int|null    $group_id   The pipeline a stage belongs to, when the picker groups by one.
     * @param string|null $group_name
     */
    public function __construct(
        public int $id,
        public string $label,
        public ?int $group_id = null,
        public ?string $group_name = null,
    ) {}
}
