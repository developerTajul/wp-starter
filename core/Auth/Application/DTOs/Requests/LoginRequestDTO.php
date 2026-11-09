<?php

declare(strict_types=1);

namespace Core\Auth\Application\DTOs\Requests;

/**
 * Carries validated login input into the Service.
 */
readonly class LoginRequestDTO
{
    public function __construct(
        public string $email,
        public string $password,
    ) {}
}
