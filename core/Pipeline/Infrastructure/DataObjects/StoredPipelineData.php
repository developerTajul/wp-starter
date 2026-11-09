<?php

declare(strict_types=1);

namespace Core\Pipeline\Infrastructure\DataObjects;

use Core\Pipeline\Domain\Entities\PipelineEntity;

/**
 * A pipeline freshly read from the database, together with the raw stage rows
 * it was read with. Both halves are handed to PipelineEntityMapper, so nothing
 * above Infrastructure has to know how Eloquent nests a relation.
 */
final readonly class StoredPipelineData
{
    /**
     * @param array<int, array<string, mixed>> $stages
     */
    public function __construct(
        public array $pipeline,
        public array $stages = [],
    ) {}
}
