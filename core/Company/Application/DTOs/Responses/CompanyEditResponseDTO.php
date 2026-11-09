<?php

declare(strict_types=1);

namespace Core\Company\Application\DTOs\Responses;

/**
 * Shape a company's stored values are handed to an edit form in. It carries
 * only the editable fields, so a form never has to reach into the fuller
 * profile DTO for the handful of values it prefills.
 */
final readonly class CompanyEditResponseDTO
{
    /**
     * @param int         $id
     * @param string      $name
     * @param string|null $website
     * @param string|null $industry
     * @param string|null $phone
     * @param string|null $address
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $website,
        public ?string $industry,
        public ?string $phone,
        public ?string $address,
    ) {}
}
