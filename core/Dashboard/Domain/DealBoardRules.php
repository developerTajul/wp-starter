<?php

declare(strict_types=1);

namespace Core\Dashboard\Domain;

use Core\Deal\Domain\DealRules;

/**
 * Holds the deal rules the dashboard reads. Nothing new is decided here: the
 * names that close a deal, and the states a stage list is bucketed into, are
 * the ones the deal module already owns. They are exposed under dashboard names
 * so the reporting code states what it means rather than importing the deal
 * module's wording.
 */
final class DealBoardRules
{
    /**
     * The stage name that counts a deal as won.
     */
    public const WON_STAGE_NAME = DealRules::WON_STAGE_NAME;

    /**
     * The stage name that counts a deal as lost.
     */
    public const LOST_STAGE_NAME = DealRules::LOST_STAGE_NAME;

    /**
     * Whether a stage name is one of the names a closed deal sits in. Matched
     * the way the deal entity matches it, so the dashboard and the write side
     * can never disagree about whether a deal is closed.
     *
     * @param string $stageName
     *
     * @return bool
     */
    public static function closesADeal(string $stageName): bool
    {
        $normalisedStageName = mb_strtolower(trim($stageName));

        return $normalisedStageName === mb_strtolower(self::WON_STAGE_NAME)
            || $normalisedStageName === mb_strtolower(self::LOST_STAGE_NAME);
    }
}
