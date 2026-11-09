<?php

declare(strict_types=1);

namespace Core\Company\Domain\Entities;

use Core\Company\Domain\CompanyRules;
use Core\Company\Domain\Exceptions\InvalidCompanyException;

/**
 * Domain entity representing a company.
 */
readonly class CompanyEntity
{
    /**
     * @param int|null    $id
     * @param string      $name
     * @param string|null $website
     * @param string|null $industry
     * @param string|null $phone
     * @param string|null $address
     * @param string|null $createdAt
     * @param string|null $updatedAt
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public ?string $website,
        public ?string $industry,
        public ?string $phone,
        public ?string $address,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}

    /**
     * Builds a new, unsaved company.
     *
     * @param string      $name
     * @param string|null $website
     * @param string|null $industry
     * @param string|null $phone
     * @param string|null $address
     *
     * @return self
     *
     * @throws InvalidCompanyException When a field would break one of the company's invariants.
     */
    public static function create(
        string $name,
        ?string $website = null,
        ?string $industry = null,
        ?string $phone = null,
        ?string $address = null,
    ): self {
        self::assertNameIsUsable($name);
        self::assertWebsiteIsUsable($website);
        self::assertIndustryIsUsable($industry);
        self::assertPhoneIsUsable($phone);

        return new self(
            id: null,
            name: $name,
            website: $website,
            industry: $industry,
            phone: $phone,
            address: $address,
        );
    }

    /**
     * Rebuilds a company from persisted state. The stored fields are taken as
     * they are, so a row written before a rule existed still loads.
     *
     * @param int         $id
     * @param string      $name
     * @param string|null $website
     * @param string|null $industry
     * @param string|null $phone
     * @param string|null $address
     * @param string|null $createdAt
     * @param string|null $updatedAt
     *
     * @return self
     */
    public static function reconstitute(
        int $id,
        string $name,
        ?string $website = null,
        ?string $industry = null,
        ?string $phone = null,
        ?string $address = null,
        ?string $createdAt = null,
        ?string $updatedAt = null,
    ): self {
        return new self(
            id: $id,
            name: $name,
            website: $website,
            industry: $industry,
            phone: $phone,
            address: $address,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    /**
     * Applies an update payload and returns the updated company. A null field
     * with no clear flag keeps the stored value; a clear flag drops it. The
     * whole payload is validated before anything is applied, so a rejected
     * update leaves the company untouched.
     *
     * @param string|null $name
     * @param string|null $website
     * @param string|null $industry
     * @param string|null $phone
     * @param string|null $address
     * @param bool        $clearWebsite  Drop the stored website.
     * @param bool        $clearIndustry Drop the stored industry.
     * @param bool        $clearPhone    Drop the stored phone number.
     * @param bool        $clearAddress  Drop the stored address.
     *
     * @return self
     *
     * @throws InvalidCompanyException When a change would break an invariant.
     */
    public function applyChanges(
        ?string $name = null,
        ?string $website = null,
        ?string $industry = null,
        ?string $phone = null,
        ?string $address = null,
        bool $clearWebsite = false,
        bool $clearIndustry = false,
        bool $clearPhone = false,
        bool $clearAddress = false,
    ): self {
        // A company is identified by its name, so an update may replace it but
        // never blank it. There is no clear flag for the name.
        $updatedName = $name ?? $this->name;
        $updatedWebsite = $clearWebsite ? null : ($website ?? $this->website);
        $updatedIndustry = $clearIndustry ? null : ($industry ?? $this->industry);
        $updatedPhone = $clearPhone ? null : ($phone ?? $this->phone);
        $updatedAddress = $clearAddress ? null : ($address ?? $this->address);

        // Guard the resulting state as a whole, so a payload rejected on one
        // field cannot be left half applied.
        self::assertNameIsUsable($updatedName);
        self::assertWebsiteIsUsable($updatedWebsite);
        self::assertIndustryIsUsable($updatedIndustry);
        self::assertPhoneIsUsable($updatedPhone);

        return new self(
            id: $this->id,
            name: $updatedName,
            website: $updatedWebsite,
            industry: $updatedIndustry,
            phone: $updatedPhone,
            address: $updatedAddress,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }

    /**
     * Guards the name invariant: a company is identified by its name, so a
     * blank one is not a company.
     *
     * @param string $name
     *
     * @return void
     *
     * @throws InvalidCompanyException When the name is blank or too long.
     */
    private static function assertNameIsUsable(string $name): void
    {
        if (trim($name) === '') {
            throw InvalidCompanyException::emptyName();
        }

        if (mb_strlen($name) > CompanyRules::MAX_NAME_LENGTH) {
            throw InvalidCompanyException::nameTooLong(mb_strlen($name));
        }
    }

    /**
     * Guards the website invariant: a supplied website has to be an absolute
     * URL, so a bare host or a relative path is rejected here rather than
     * stored as one.
     *
     * @param string|null $website
     *
     * @return void
     *
     * @throws InvalidCompanyException When the website is not a usable absolute URL.
     */
    private static function assertWebsiteIsUsable(?string $website): void
    {
        if ($website === null || $website === '') {
            return;
        }

        $host = parse_url($website, PHP_URL_HOST);

        if ($host === null || $host === false) {
            throw InvalidCompanyException::invalidWebsite($website);
        }

        if (! in_array(parse_url($website, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw InvalidCompanyException::invalidWebsite($website);
        }

        if (mb_strlen($website) > CompanyRules::MAX_WEBSITE_LENGTH) {
            throw InvalidCompanyException::websiteTooLong(mb_strlen($website));
        }
    }

    /**
     * Guards the industry invariant: a supplied industry has to fit the column.
     *
     * @param string|null $industry
     *
     * @return void
     *
     * @throws InvalidCompanyException When the industry is longer than the column accepts.
     */
    private static function assertIndustryIsUsable(?string $industry): void
    {
        if ($industry !== null && mb_strlen($industry) > CompanyRules::MAX_INDUSTRY_LENGTH) {
            throw InvalidCompanyException::industryTooLong(mb_strlen($industry));
        }
    }

    /**
     * Guards the phone invariant: a supplied phone number has to fit the column.
     *
     * @param string|null $phone
     *
     * @return void
     *
     * @throws InvalidCompanyException When the phone number is longer than the column accepts.
     */
    private static function assertPhoneIsUsable(?string $phone): void
    {
        if ($phone !== null && mb_strlen($phone) > CompanyRules::MAX_PHONE_LENGTH) {
            throw InvalidCompanyException::phoneTooLong(mb_strlen($phone));
        }
    }
}
