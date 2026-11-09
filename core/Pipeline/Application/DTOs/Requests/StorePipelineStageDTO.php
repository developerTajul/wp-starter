<?php

declare(strict_types=1);

namespace Core\Pipeline\Application\DTOs\Requests;

/**
 * Carries one validated stage of a store request INTO the Service. Immutable.
 */
final readonly class StorePipelineStageDTO
{
    /**
     * @param string $name
     * @param int    $orderPosition
     * @param int    $probability
     */
    public function __construct(
        public string $name,
        public int $orderPosition,
        public int $probability,
    ) {}
}
