<?php

declare(strict_types=1);

namespace Core\Activity\Domain\Enums;

enum ActivityType: string
{
    case CALL = 'call';
    case MEETING = 'meeting';
    case EMAIL = 'email';
    case TASK = 'task';

    public function label(): string
    {
        return match ($this) {
            self::CALL => 'Call',
            self::MEETING => 'Meeting',
            self::EMAIL => 'Email',
            self::TASK => 'Task',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}