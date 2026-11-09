<?php

declare(strict_types=1);

namespace Core\Auth\Application\Mappers;

use Core\Auth\Application\DTOs\Responses\ProfileResponseDTO;
use Core\User\Domain\Entities\UserEntity;

/**
 * Maps a UserEntity to the profile read's response DTO.
 */
final class ProfileResponseMapper
{
    /**
     * @param UserEntity $entity
     *
     * @return ProfileResponseDTO
     */
    public function toResponseDTO(UserEntity $entity): ProfileResponseDTO
    {
        return new ProfileResponseDTO(
            id: (int) $entity->id,
            name: $entity->name,
            email: $entity->email,
            role: $entity->role->value,
            status: $entity->status->value,
        );
    }
}
