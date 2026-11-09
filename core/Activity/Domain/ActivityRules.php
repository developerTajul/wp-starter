<?php

declare(strict_types=1);

namespace Core\Activity\Domain;

/**
 * Holds the activity module's shared numeric rules.
 */
final class ActivityRules
{
    /**
     * The largest page size an activity list may be asked for.
     */
    public const MAX_PER_PAGE = 100;

    /**
     * The smallest page size an activity list may be asked for.
     */
    public const MIN_PER_PAGE = 1;
}
