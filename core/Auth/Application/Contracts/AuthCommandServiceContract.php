<?php

declare(strict_types=1);

namespace Core\Auth\Application\Contracts;

use Core\Auth\Application\DTOs\Requests\RegisterRequestDTO;
use Core\Shared\Application\DTOs\Result;

/**
 * PORT: the auth module's write surface.
 *
 * Delivery adapters inject this contract, never the concrete service.
 */
interface AuthCommandServiceContract
{
    /**
     * Registers a new account.
     *
     * @param RegisterRequestDTO $registerDTO
     *
     * @return Result Contains the AuthResponseDTO on success.
     */
    public function register(RegisterRequestDTO $registerDTO): Result;
}
