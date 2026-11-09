<?php

declare(strict_types=1);

namespace Core\Auth\Domain\Enums;

enum AuthRole: string
{
    case ADMIN = 'ADMIN';
    case MODERATOR = 'MODERATOR';
    case MEMBER = 'MEMBER';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Admin',
            self::MODERATOR => 'Moderator',
            self::MEMBER => 'Member',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}