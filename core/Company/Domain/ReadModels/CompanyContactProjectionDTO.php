<?php

declare(strict_types=1);

namespace Core\Company\Domain\ReadModels;

/**
 * A contact row as the company detail read returns it. The company_contact
 * pivot supplies the job title and the primary flag.
 */
final readonly class CompanyContactProjectionDTO
{
    /**
     * @param int         $id
     * @param string      $name
     * @param string|null $email
     * @param string|null $phone
     * @param string|null $jobTitle
     * @param bool        $isPrimary
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $jobTitle,
        public bool $isPrimary,
    ) {}
}
