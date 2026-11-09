<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Queues;

use App\Jobs\ProcessInboundLead;
use App\Jobs\UpdateLeadStatusJob;
use Core\Shared\Application\Contracts\QueueManagerContract;
use Core\Shared\Application\DTOs\WriteDTO;
use InvalidArgumentException;

/**
 * Pushes work onto Laravel's queue.
 *
 * @implements \Core\Shared\Application\Contracts\QueueManagerContract
 */
final class LaravelQueueManager implements QueueManagerContract
{
    private const JOB_MAP = [
        'lead.inbound.process' => ProcessInboundLead::class,
        'lead_status_update_topic' => UpdateLeadStatusJob::class,
    ];

    /**
     * Queues the job registered under the given identifier.
     *
     * @param string   $jobIdentifier
     * @param WriteDTO $data
     *
     * @return void
     *
     * @throws InvalidArgumentException When no job is registered under the identifier.
     */
    public function push(string $jobIdentifier, WriteDTO $data): void
    {
        if (! array_key_exists($jobIdentifier, self::JOB_MAP)) {
            throw new InvalidArgumentException("Unrecognized job identifier: {$jobIdentifier}");
        }

        dispatch(new (self::JOB_MAP[$jobIdentifier])($data));
    }
}
