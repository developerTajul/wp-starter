<?php

declare(strict_types=1);

namespace Core\Deal\Domain\ValueObjects;

use Core\Deal\Domain\DealRules;
use Core\Deal\Domain\Exceptions\InvalidDealException;

/**
 * Filter criteria the repository uses to narrow down the deal list.
 * Validates itself, so the criteria reaching Infrastructure are always usable.
 */
final readonly class DealFilter
{
    /**
     * @param int|null $pipelineId  Only deals in this pipeline.
     * @param int|null $dealStageId Only deals in this stage.
     * @param int      $perPage
     *
     * @throws InvalidDealException When the page size falls outside its bounds.
     */
    public function __construct(
        public ?int $pipelineId = null,
        public ?int $dealStageId = null,
        public int $perPage = DealRules::DEFAULT_PER_PAGE,
    ) {
        if ($perPage < DealRules::MIN_PER_PAGE || $perPage > DealRules::MAX_PER_PAGE) {
            throw InvalidDealException::invalidPerPage($perPage);
        }
    }
}
