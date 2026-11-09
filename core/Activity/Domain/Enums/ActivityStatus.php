<?php

declare(strict_types=1);

namespace Core\Activity\Domain\Enums;

/**
 * Completion state an activity list can be narrowed down to.
 */
enum ActivityStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::COMPLETED => 'Completed',
        };
    }
}
