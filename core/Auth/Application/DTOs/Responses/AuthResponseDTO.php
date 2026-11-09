<?php

declare(strict_types=1);

namespace Core\Auth\Application\DTOs\Responses;
use JsonSerializable;

/**
 * Response DTO for an authenticated user resource.
 */
readonly class AuthResponseDTO implements JsonSerializable
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $role,
        public string $status,
    ) {}


    public function toArray(): array
    {
        return [
            'id'     => $this->id,
            'name'   => $this->name,
            'email'  => $this->email,
            'role'   => $this->role,
            'status' => $this->status,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
