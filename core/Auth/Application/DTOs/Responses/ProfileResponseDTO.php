<?php

declare(strict_types=1);

namespace Core\Auth\Application\DTOs\Responses;

use JsonSerializable;

/**
 * Response DTO for the profile read.
 *
 * Carries the status code rather than its label: the label is delivery text and
 * is resolved from the code by whichever channel renders the profile.
 */
final readonly class ProfileResponseDTO implements JsonSerializable
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $role,
        public string $status,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
