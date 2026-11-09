<?php

declare(strict_types=1);

namespace Core\Activity\Application\DTOs\Requests;

use Core\Activity\Domain\Enums\ActivityPeriod;
use Core\Activity\Domain\Enums\ActivityStatus;
use Core\Activity\Domain\Enums\ActivityType;

/**
 * Carries the activity list criteria into the Service. Built by ActivityMapper.
 */
final readonly class ActivityFilterDTO
{
    public function __construct(
        public ?ActivityStatus $status = null,
        public ?ActivityType $type = null,
        public ?int $userId = null,
        public ?ActivityPeriod $filter = null,
        public int $perPage = 15,
        public ?string $trackableType = null,
        public ?int $trackableId = null
    ) {}
}
