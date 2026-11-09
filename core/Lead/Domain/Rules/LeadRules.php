<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Rules;

/**
 * Holds the lead module's shared numeric rules.
 */
final class LeadRules
{
    /**
     * The largest page size a lead list may be asked for.
     */
    public const MAX_PER_PAGE = 100;

    /**
     * The smallest page size a lead list may be asked for.
     */
    public const MIN_PER_PAGE = 1;

    public const DEFAULT_PER_PAGE = 15;

    /**
     * Cuts a requested page size down to one the lead list can serve.
     *
     * Zero or a negative value means no page size was really asked for, so the
     * default stands in. Anything above the maximum is cut to the maximum
     * rather than dropped: the list still renders at the widest page allowed,
     * and an oversized per_page cannot ask the database for the whole table.
     *
     * @param int $perPage The page size the caller asked for.
     *
     * @return int A page size between the module's own bounds.
     */
    public static function clampPerPage(int $perPage): int
    {
        if ($perPage <= 0) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    /**
     * The largest number of timeline entries the lead detail page reads.
     */
    public const TIMELINE_MAX_LIMIT = 50;

    /**
     * The smallest number of timeline entries the lead detail page reads.
     */
    public const TIMELINE_MIN_LIMIT = 1;

    /**
     * How many timeline entries the lead detail page reads by default.
     */
    public const TIMELINE_DEFAULT_LIMIT = 20;

    /**
     * The widest name the leads table's name column accepts.
     */
    public const MAX_NAME_LENGTH = 255;
}