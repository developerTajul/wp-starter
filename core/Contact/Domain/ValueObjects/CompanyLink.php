<?php

declare(strict_types=1);

namespace Core\Contact\Domain\ValueObjects;

use Core\Contact\Domain\Rules\ContactRules;
use Core\Contact\Domain\Exceptions\InvalidContactException;

/**
 * The company a contact is attached to, together with the role it holds there.
 *
 * The link is created from input with the pivot values alone. The company's own
 * display fields are filled in only when the link is rebuilt from storage, so a
 * write path never has to carry data it did not receive.
 */
final readonly class CompanyLink
{
    /**
     * @param int         $companyId
     * @param string|null $jobTitle
     * @param bool        $isPrimary
     * @param int|null    $companyName     Display name, known only after a read.
     * @param string|null $companyPhone    Display phone, known only after a read.
     * @param string|null $companyWebsite  Display website, known only after a read.
     * @param string|null $companyIndustry Display industry, known only after a read.
     */
    private function __construct(
        public int $companyId,
        public ?string $jobTitle,
        public bool $isPrimary,
        public ?string $companyName = null,
        public ?string $companyPhone = null,
        public ?string $companyWebsite = null,
        public ?string $companyIndustry = null,
    ) {}

    /**
     * Builds a link, guarding the values that reach the pivot table.
     *
     * @param int         $companyId
     * @param string|null $jobTitle
     * @param bool        $isPrimary
     *
     * @return self
     *
     * @throws InvalidContactException When the company ID is not a usable one, or the job title is too long.
     */
    public static function create(int $companyId, ?string $jobTitle, bool $isPrimary): self
    {
        if ($companyId <= 0) {
            throw InvalidContactException::invalidCompanyId($companyId);
        }

        if ($jobTitle !== null && mb_strlen($jobTitle) > ContactRules::MAX_JOB_TITLE_LENGTH) {
            throw InvalidContactException::jobTitleTooLong(mb_strlen($jobTitle));
        }

        return new self(
            companyId: $companyId,
            jobTitle: $jobTitle,
            isPrimary: $isPrimary,
        );
    }

    /**
     * Rebuilds a link from persisted state, together with the company's display
     * fields read alongside the pivot row. The stored values are taken as they
     * are, so a row written before a rule existed still loads.
     *
     * @param int         $companyId
     * @param string|null $jobTitle
     * @param bool        $isPrimary
     * @param string|null $companyName
     * @param string|null $companyPhone
     * @param string|null $companyWebsite
     * @param string|null $companyIndustry
     *
     * @return self
     */
    public static function reconstitute(
        int $companyId,
        ?string $jobTitle = null,
        bool $isPrimary = false,
        ?string $companyName = null,
        ?string $companyPhone = null,
        ?string $companyWebsite = null,
        ?string $companyIndustry = null,
    ): self {
        return new self(
            companyId: $companyId,
            jobTitle: $jobTitle,
            isPrimary: $isPrimary,
            companyName: $companyName,
            companyPhone: $companyPhone,
            companyWebsite: $companyWebsite,
            companyIndustry: $companyIndustry,
        );
    }
}
