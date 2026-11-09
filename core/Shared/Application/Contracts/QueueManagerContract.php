<?php
declare(strict_types=1);

namespace Core\Shared\Application\Contracts;

use Core\Shared\Application\DTOs\WriteDTO;

interface QueueManagerContract 
{
    /**
     * Pushes a data payload to a specific queue or topic.
     * 
     * @param string $jobIdentifier The unique identifier or topic for the job.
     * @param WriteDTO $data The immutable data to be processed.
     */
    public function push(string $jobIdentifier, WriteDTO $data): void;
}