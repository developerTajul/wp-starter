<?php

declare(strict_types=1);

namespace Core\Deal\Domain;

/**
 * Holds the deal module's shared numeric rules.
 */
final class DealRules
{
    public const MAX_TITLE_LENGTH = 255;

    public const CURRENCY_LENGTH = 3;

    public const MIN_AMOUNT = '0.00';

    /**
     * The amount column holds 15 digits, 2 of them decimals.
     */
    public const MAX_AMOUNT = '9999999999999.99';

    /**
     * The stage names the seed data uses to close a deal. The deals table backs
     * the rule with no column of its own, so a stage counts as closing one when
     * it carries one of these names. A pipeline can name its own stages
     * anything, so the check cannot be an ID: stage 5 is "Closed Won" in the
     * default pipeline and "Closing" in every other one.
     */
    public const WON_STAGE_NAME = 'Closed Won';

    public const LOST_STAGE_NAME = 'Closed Lost';

    /**
     * The stage a deal opens in when nobody chose one. Stated as a name for
     * the same reason as the closing stages above: a pipeline names its own
     * stages, so the first stage is not reliably id 1.
     */
    public const OPENING_STAGE_NAME = 'Discovery';

    /**
     * The amount and currency a conversion-born deal starts with. A lead that
     * has just converted has no price agreed yet, and the column is decimal,
     * so the value is a string (P2).
     */
    public const DEFAULT_AMOUNT = '0.00';

    public const DEFAULT_CURRENCY = 'BDT';

    /**
     * The page-size bounds the deal list accepts.
     */
    public const DEFAULT_PER_PAGE = 15;

    public const MIN_PER_PAGE = 1;

    public const MAX_PER_PAGE = 100;
}
