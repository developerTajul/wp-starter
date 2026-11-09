<?php

declare(strict_types=1);

namespace Core\Company\Application\DTOs\Responses;

/**
 * Shape the stage a company deal sits in is handed to the delivery layer in.
 */
final readonly class CompanyDealStageResponseDTO
{
    /**
     * @param int|null    $id
     * @param string      $name
     * @param int|null    $order_position
     * @param int|null    $probability
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public ?int $order_position,
        public ?int $probability,
    ) {}
}
