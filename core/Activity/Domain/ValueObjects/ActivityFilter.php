<?php

declare(strict_types=1);

namespace Core\Activity\Domain\ValueObjects;

use Core\Activity\Domain\ActivityRules;
use Core\Activity\Domain\Enums\ActivityPeriod;
use Core\Activity\Domain\Enums\ActivityStatus;
use Core\Activity\Domain\Enums\ActivityType;
use Core\Activity\Domain\Exceptions\InvalidActivityException;

/**
 * Filter criteria the repository uses to narrow down the activity list.
 * Validates itself, so the criteria reaching Infrastructure are always usable.
 */
final readonly class ActivityFilter
{
    /**
     * @param ActivityStatus|null $status
     * @param ActivityType|null   $type
     * @param int|null            $userId
     * @param ActivityPeriod|null $period
     * @param int                 $perPage
     * @param string|null         $trackableType Morph alias of the tracked record, e.g. "lead".
     * @param int|null            $trackableId   ID of the tracked record.
     *
     * @throws InvalidActivityException When the page size falls outside its bounds,
     *                                  or a trackable pair is only half supplied.
     */
    public function __construct(
        public ?ActivityStatus $status = null,
        public ?ActivityType $type = null,
        public ?int $userId = null,
        public ?ActivityPeriod $period = null,
        public int $perPage = 15,
        public ?string $trackableType = null,
        public ?int $trackableId = null,
    ) {
        if ($perPage < ActivityRules::MIN_PER_PAGE || $perPage > ActivityRules::MAX_PER_PAGE) {
            throw InvalidActivityException::invalidPerPage($perPage);
        }

        // A half-supplied pair would silently widen the result set to every
        // activity of that type, or every activity of that ID, so it is
        // rejected here instead of being passed down as a partial filter.
        if (($trackableType === null) !== ($trackableId === null)) {
            throw InvalidActivityException::incompleteTrackableFilter();
        }
    }
}
