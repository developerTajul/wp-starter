<?php

declare(strict_types=1);

namespace Core\Deal\Application\DTOs\Responses;

/**
 * Carries the pipeline a deal sits in.
 */
final readonly class DealPipelineResponseDTO
{
    /**
     * @param int    $id
     * @param string $name
     * @param bool   $is_default
     */
    public function __construct(
        public int $id,
        public string $name,
        public bool $is_default,
    ) {}
}
