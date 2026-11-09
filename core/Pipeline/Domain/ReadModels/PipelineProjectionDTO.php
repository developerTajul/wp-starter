<?php

declare(strict_types=1);

namespace Core\Pipeline\Domain\ReadModels;

/**
 * A pipeline row as the reads return it: the columns the pipeline screens show
 * and nothing else. Hydrating a full Entity for a read is what this read model
 * exists to avoid.
 */
final readonly class PipelineProjectionDTO
{
    /**
     * @param int         $id
     * @param string      $name
     * @param bool        $isDefault
     * @param string|null $createdAt
     * @param string|null $updatedAt
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $isDefault,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
