<?php

declare(strict_types=1);

namespace Core\Company\Application\DTOs\Requests;

/**
 * Carries validated delivery-layer input INTO the Service. Immutable.
 * Built EXCLUSIVELY by the module's Request Mapper - no factory methods here.
 */
final readonly class StoreCompanyDTO
{
    /**
     * @param string      $name
     * @param string|null $website
     * @param string|null $industry
     * @param string|null $phone
     * @param string|null $address
     */
    public function __construct(
        public string $name,
        public ?string $website = null,
        public ?string $industry = null,
        public ?string $phone = null,
        public ?string $address = null,
    ) {}
}
