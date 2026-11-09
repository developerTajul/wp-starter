<?php

declare(strict_types=1);

namespace Core\Contact\Application\DTOs\Requests;

use Core\Contact\Domain\Rules\ContactRules;

/**
 * Carries validated delivery-layer query input INTO the Service. Immutable.
 */
final readonly class ContactFilterDTO
{
    /**
     * @param string|null $search
     * @param int         $perPage
     */
    public function __construct(
        public ?string $search = null,
        public int $perPage = ContactRules::DEFAULT_PER_PAGE,
    ) {}
}
