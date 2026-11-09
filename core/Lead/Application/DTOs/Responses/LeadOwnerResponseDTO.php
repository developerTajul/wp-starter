<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Responses;

/**
 * Shape the owner change is handed back in. Property names are the wire keys.
 */
final readonly class LeadOwnerResponseDTO
{
    /**
     * @param int         $id               The lead that changed hands.
     * @param int|null    $assigned_to      The new owner's id, or null when released.
     * @param string|null $assigned_to_name The new owner's name, or null when released.
     */
    public function __construct(
        public int $id,
        public ?int $assigned_to,
        public ?string $assigned_to_name,
    ) {}
}
