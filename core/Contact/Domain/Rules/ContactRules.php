<?php

declare(strict_types=1);

namespace Core\Contact\Domain\Rules;

/**
 * Holds the contact module's shared numeric rules.
 */
final class ContactRules
{
    /**
     * The page size a contact list falls back to when none is asked for.
     */
    public const DEFAULT_PER_PAGE = 15;

    /**
     * The largest page size a contact list may be asked for.
     */
    public const MAX_PER_PAGE = 100;

    /**
     * The smallest page size a contact list may be asked for.
     */
    public const MIN_PER_PAGE = 1;

    public const MAX_NAME_LENGTH = 255;

    public const MAX_EMAIL_LENGTH = 255;

    public const MAX_PHONE_LENGTH = 50;

    public const MAX_JOB_TITLE_LENGTH = 255;

    /**
     * Cuts a requested page size down to one the contact list can serve.
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
}
