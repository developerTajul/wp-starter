<?php

declare(strict_types=1);

namespace Core\Auth\Infrastructure\Mappers;

use Core\User\Domain\Entities\UserEntity;
use Core\Auth\Domain\Enums\AuthRole;
use Core\Auth\Domain\Enums\AuthStatus;

/**
 * Maps raw users-table columns to UserEntity and back.
 */
final class AuthEntityMapper
{
    /**
     * Rebuilds an account from its raw column values.
     *
     * @param array<string, mixed> $data
     *
     * @return UserEntity
     */
    public function toEntity(array $data): UserEntity
    {
        return UserEntity::reconstitute(
            id: isset($data['id']) ? (int) $data['id'] : null,
            name: (string) $data['name'],
            email: (string) $data['email'],
            emailVerifiedAt: $this->stringOrNull($data['email_verified_at'] ?? null),
            password: (string) $data['password'],
            image: $this->stringOrNull($data['image'] ?? null),
            role: AuthRole::tryFrom((string) ($data['role'] ?? '')) ?? AuthRole::MEMBER,
            status: AuthStatus::tryFrom((string) ($data['status'] ?? '')) ?? AuthStatus::PENDING,
            rememberToken: $this->stringOrNull($data['remember_token'] ?? null),
            createdAt: $this->stringOrNull($data['created_at'] ?? null),
            updatedAt: $this->stringOrNull($data['updated_at'] ?? null),
            deletedAt: $this->stringOrNull($data['deleted_at'] ?? null),
        );
    }

    /**
     * Reduces an account to the columns the users table stores.
     *
     * The created_at and updated_at columns are left to Eloquent; writing them
     * explicitly here would overwrite the timestamps on the row.
     *
     * @param UserEntity $entity
     *
     * @return array<string, mixed>
     */
    public function toPersistenceArray(UserEntity $entity): array
    {
        return [
            'name' => $entity->name,
            'email' => $entity->email,
            'password' => $entity->password,
            'image' => $entity->image,
            'role' => $entity->role->value,
            'status' => $entity->status->value,
        ];
    }

    /**
     * @param mixed $value
     *
     * @return string|null
     */
    private function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
