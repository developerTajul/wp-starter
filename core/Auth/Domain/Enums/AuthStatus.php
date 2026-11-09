<?php

declare(strict_types=1);

namespace Core\Auth\Domain\Enums;

enum AuthStatus: string
{
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case SUSPENDED = 'SUSPENDED';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Approval',
            self::APPROVED => 'Approved',
            self::SUSPENDED => 'Suspended',
        };
    }
}