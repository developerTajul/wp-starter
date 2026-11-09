<?php

declare(strict_types=1);

namespace Core\Deal\Domain\DataObjects;

/**
 * The rows the deal form offers for each of its pickers, as plain column rows.
 * Flattened in Infrastructure, so nothing above it has to know how the related
 * tables are read.
 */
final readonly class DealFormOptions
{
    /**
     * @param array<int, array<string, mixed>> $pipelines
     * @param array<int, array<string, mixed>> $stages
     * @param array<int, array<string, mixed>> $companies
     * @param array<int, array<string, mixed>> $contacts
     */
    public function __construct(
        public array $pipelines = [],
        public array $stages = [],
        public array $companies = [],
        public array $contacts = [],
    ) {}
}
