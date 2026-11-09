<?php

declare(strict_types=1);

namespace Core\Activity\Domain\Enums;

/**
 * Due-date window an activity list can be narrowed down to.
 */
enum ActivityPeriod: string
{
    case TODAY = 'today';
    case OVERDUE = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::TODAY => 'Due today',
            self::OVERDUE => 'Overdue',
        };
    }
}
