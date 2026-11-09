<?php

declare(strict_types=1);

namespace Core\Auth\Application\Mappers;

use Core\Auth\Application\Contracts\AuthMapperContract;
use Core\Auth\Application\DTOs\Requests\LoginRequestDTO;
use Core\Auth\Application\DTOs\Requests\ProfileRequestDTO;
use Core\Auth\Application\DTOs\Requests\RegisterRequestDTO;

/**
 * Maps validated auth input into request DTOs.
 *
 * @implements AuthMapperContract
 */
final class AuthRequestMapper implements AuthMapperContract
{
    /**
     * Builds a RegisterRequestDTO from validated input.
     *
     * @param array<string, mixed> $validatedData
     */
    public function mapToRegisterDTO(array $validatedData): RegisterRequestDTO
    {
        return new RegisterRequestDTO(
            name: $validatedData['name'],
            email: $validatedData['email'],
            password: $validatedData['password'],
            image: $validatedData['image'] ?? null,
        );
    }

    /**
     * Builds a LoginRequestDTO from validated input.
     *
     * @param array<string, mixed> $validatedData
     */
    public function mapToLoginDTO(array $validatedData): LoginRequestDTO
    {
        return new LoginRequestDTO(
            email: $validatedData['email'],
            password: $validatedData['password'],
        );
    }

    /**
     * Builds a ProfileRequestDTO for the user being read.
     *
     * @param int $userId
     */
    public function mapToProfileDTO(int $userId): ProfileRequestDTO
    {
        return new ProfileRequestDTO(userId: $userId);
    }
}
