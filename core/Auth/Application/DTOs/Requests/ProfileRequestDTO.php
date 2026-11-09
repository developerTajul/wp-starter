<?php

declare(strict_types=1);

namespace Core\Auth\Application\DTOs\Requests;

/**
 * Carries the id of the user whose profile is being read.
 */
readonly class ProfileRequestDTO
{
    public function __construct(
        public int $userId,
    ) {}
}
