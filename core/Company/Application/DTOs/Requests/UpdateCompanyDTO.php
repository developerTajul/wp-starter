<?php

declare(strict_types=1);

namespace Core\Company\Application\DTOs\Requests;

/**
 * Carries validated delivery-layer input INTO the Service. Immutable.
 * Built EXCLUSIVELY by the module's Request Mapper - no factory methods here.
 *
 * A partial update has three states per field: absent (leave the stored value
 * alone), an explicit null (clear it), or a value (replace it). A plain
 * nullable field collapses the first two into one null, so a clear flag
 * carries the "drop this" intent alongside it.
 */
final readonly class UpdateCompanyDTO
{
    /**
     * @param int         $id
     * @param string|null $name
     * @param string|null $website
     * @param string|null $industry
     * @param string|null $phone
     * @param string|null $address
     * @param bool        $clearWebsite  Drop the stored website.
     * @param bool        $clearIndustry Drop the stored industry.
     * @param bool        $clearPhone    Drop the stored phone number.
     * @param bool        $clearAddress  Drop the stored address.
     */
    public function __construct(
        public int $id,
        public ?string $name = null,
        public ?string $website = null,
        public ?string $industry = null,
        public ?string $phone = null,
        public ?string $address = null,
        public bool $clearWebsite = false,
        public bool $clearIndustry = false,
        public bool $clearPhone = false,
        public bool $clearAddress = false,
    ) {}
}
