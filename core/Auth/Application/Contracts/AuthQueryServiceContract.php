<?php

declare(strict_types=1);

namespace Core\Auth\Application\Contracts;

use Core\Auth\Application\DTOs\Requests\LoginRequestDTO;
use Core\Auth\Application\DTOs\Requests\ProfileRequestDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * PORT: the auth module's read surface.
 *
 * Delivery adapters inject this contract, never the concrete service.
 */
interface AuthQueryServiceContract
{
    /**
     * Verifies credentials and returns the account they belong to.
     * Session creation belongs to the delivery layer.
     *
     * @param LoginRequestDTO $loginDTO
     *
     * @return Result Contains the AuthResponseDTO on success.
     */
    public function login(LoginRequestDTO $loginDTO): Result;

    /**
     * Reads the profile of the given account.
     *
     * @param ProfileRequestDTO $profileDTO
     *
     * @return Result Contains the ProfileResponseDTO on success.
     */
    public function getProfile(ProfileRequestDTO $profileDTO): Result;
}
