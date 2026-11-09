<?php

declare(strict_types=1);

namespace Core\Auth\Application\Contracts;

use Core\Auth\Application\DTOs\Requests\LoginRequestDTO;
use Core\Auth\Application\DTOs\Requests\ProfileRequestDTO;
use Core\Auth\Application\DTOs\Requests\RegisterRequestDTO;

/**
 * Port for mapping validated auth input into request DTOs.
 */
interface AuthMapperContract
{
    /**
     * Builds a RegisterRequestDTO from validated input.
     *
     * @param array<string, mixed> $validatedData
     *
     * @return RegisterRequestDTO
     */
    public function mapToRegisterDTO(array $validatedData): RegisterRequestDTO;

    /**
     * Builds a LoginRequestDTO from validated input.
     *
     * @param array<string, mixed> $validatedData
     *
     * @return LoginRequestDTO
     */
    public function mapToLoginDTO(array $validatedData): LoginRequestDTO;

    /**
     * Builds a ProfileRequestDTO for the user being read.
     *
     * @param int $userId
     *
     * @return ProfileRequestDTO
     */
    public function mapToProfileDTO(int $userId): ProfileRequestDTO;
}
