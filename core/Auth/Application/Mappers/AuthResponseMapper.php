<?php

declare(strict_types=1);

namespace Core\Auth\Application\Mappers;

use Core\Auth\Application\DTOs\Responses\AuthResponseDTO;
use Core\User\Domain\Entities\UserEntity;

/**
 * Maps a UserEntity to AuthResponseDTO.
 */
final class AuthResponseMapper
{
    /**
     * @param UserEntity $entity
     *
     * @return AuthResponseDTO
     */
    public function toResponseDTO(UserEntity $entity): AuthResponseDTO
    {
        return new AuthResponseDTO(
            id: (int) $entity->id,
            name: $entity->name,
            email: $entity->email,
            role: $entity->role->value,
            status: $entity->status->value,
        );
    }
}
