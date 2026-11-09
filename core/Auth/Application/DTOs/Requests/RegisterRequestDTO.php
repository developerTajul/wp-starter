<?php

declare(strict_types=1);

namespace Core\Auth\Application\DTOs\Requests;

use Core\Auth\Domain\Enums\AuthRole;
use Core\Auth\Domain\Enums\AuthStatus;

/**
 * Carries validated registration input into the Service.
 */
readonly class RegisterRequestDTO
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public mixed $image = null,
        public AuthRole $role = AuthRole::MEMBER,
        public AuthStatus $status = AuthStatus::APPROVED,
    ) {}
}
