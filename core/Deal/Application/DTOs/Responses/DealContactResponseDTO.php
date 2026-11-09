<?php

declare(strict_types=1);

namespace Core\Deal\Application\DTOs\Responses;

/**
 * Carries the contact a deal is linked to.
 */
final readonly class DealContactResponseDTO
{
    /**
     * @param int    $id
     * @param string $name
     */
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
