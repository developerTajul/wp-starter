<?php

declare(strict_types=1);

namespace Core\Deal\Application\DTOs\Responses;

/**
 * Carries the stage a deal sits in.
 */
final readonly class DealStageResponseDTO
{
    /**
     * @param int    $id
     * @param string $name
     * @param int    $probability
     * @param int    $order_position
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $probability,
        public int $order_position,
    ) {}
}
