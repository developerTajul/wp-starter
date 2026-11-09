<?php

declare(strict_types=1);

namespace Core\Dashboard\Domain\ValueObjects;

/**
 * Figures for the activities the dashboard reports on, split by whether the
 * activity is still outstanding or already done.
 */
final readonly class ActivityMetrics
{
    /**
     * @param int $pending
     * @param int $completed
     */
    public function __construct(
        public int $pending,
        public int $completed,
    ) {}
}
