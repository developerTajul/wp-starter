<?php

declare(strict_types=1);

namespace Core\Shared\Application\DTOs;

/**
 * Base class for all Write DTOs.
 * This ensures that any DTO intended for writing data 
 * can be handled generically by services like QueueManager.
 */
abstract readonly class WriteDTO
{
}