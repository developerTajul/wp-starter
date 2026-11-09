<?php

declare(strict_types=1);

namespace Core\Contact\Application\DTOs\Requests;

/**
 * Carries validated delivery-layer input INTO the Service, including the
 * optional company linkage fields. Immutable.
 */
final readonly class StoreContactDTO
{
    /**
     * @param string      $name
     * @param string      $email
     * @param string|null $phone
     * @param int|null    $companyId
     * @param string|null $jobTitle
     * @param bool        $isPrimary
     */
    public function __construct(
        public string $name,
        public string $email,
        public ?string $phone = null,
        public ?int $companyId = null,
        public ?string $jobTitle = null,
        public bool $isPrimary = false,
    ) {}
}
