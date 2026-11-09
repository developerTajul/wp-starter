<?php

declare(strict_types=1);

namespace Core\Lead\Application\DTOs\Responses;

/**
 * One row of the lead owner picker.
 *
 * Carries only what the picker prints, so a user's credential-free read model
 * is not passed up for a dropdown that shows a name.
 */
final readonly class LeadOwnerOptionDTO
{
    /**
     * @param int    $id   The user's id, used as the option value.
     * @param string $name The user's name, printed as the option label.
     */
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
