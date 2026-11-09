<?php

declare(strict_types=1);

namespace Core\Company\Domain;

/**
 * Holds the company module's shared numeric rules.
 */
final class CompanyRules
{
    /**
     * The largest page size a company list may be asked for.
     */
    public const MAX_PER_PAGE = 100;

    /**
     * The smallest page size a company list may be asked for.
     */
    public const MIN_PER_PAGE = 1;

    public const MAX_NAME_LENGTH = 255;

    public const MAX_WEBSITE_LENGTH = 255;

    public const MAX_INDUSTRY_LENGTH = 100;

    public const MAX_PHONE_LENGTH = 50;
}
