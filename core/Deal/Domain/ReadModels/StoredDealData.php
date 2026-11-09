<?php

declare(strict_types=1);

namespace Core\Deal\Domain\ReadModels;

use Core\Deal\Domain\Entities\DealEntity;

/**
 * One deal together with the rows the detail read drags in. The related rows are
 * flattened, so nothing above Infrastructure has to know how the relations are
 * read. Only the single-record detail read uses this: a list read returns
 * DealProjectionDTO rows and never hydrates an entity.
 */
final readonly class StoredDealData
{
    /**
     * @param DealEntity                       $deal
     * @param array<string, mixed>|null        $company
     * @param array<string, mixed>|null        $contact
     * @param array<string, mixed>|null        $pipeline
     * @param array<string, mixed>|null        $stage
     * @param array<int, array<string, mixed>> $activities
     */
    public function __construct(
        public DealEntity $deal,
        public ?array $company = null,
        public ?array $contact = null,
        public ?array $pipeline = null,
        public ?array $stage = null,
        public array $activities = [],
    ) {}
}
