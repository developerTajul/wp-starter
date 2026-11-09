<?php

declare(strict_types=1);

namespace Core\Company\Domain\Exceptions;

use Core\Company\Domain\CompanyRules;
use RuntimeException;

/**
 * Thrown when a company would be left in an invalid state.
 *
 * Carries the translation key for the rule alongside its message. The key is
 * what delivery translates; the message is the untranslated default, so a
 * caller with no translator still gets something readable and the domain
 * never holds presentation wording.
 */
final class InvalidCompanyException extends RuntimeException
{
    /**
     * Translations for this exception live under this path, followed by the
     * specific rule (e.g. companies.errors.empty_name).
     */
    public const TRANSLATION_PREFIX = 'companies.errors.';

    /**
     * @param string                $message        Untranslated default wording.
     * @param string                $translationKey
     * @param array<string, string> $replace        Replacement values for the key.
     */
    public function __construct(
        string $message,
        public readonly string $translationKey,
        public readonly array $replace = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Reports a missing or blank name.
     *
     * @return self
     */
    public static function emptyName(): self
    {
        return new self(
            message: 'A company must carry a name.',
            translationKey: self::TRANSLATION_PREFIX . 'empty_name',
        );
    }

    /**
     * Reports a name longer than the name column accepts.
     *
     * @param int $length
     *
     * @return self
     */
    public static function nameTooLong(int $length): self
    {
        return new self(
            message: sprintf(
                'A company name cannot exceed %d characters, %d given.',
                CompanyRules::MAX_NAME_LENGTH,
                $length,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'name_too_long',
            replace: [
                'max' => (string) CompanyRules::MAX_NAME_LENGTH,
                'given' => (string) $length,
            ],
        );
    }

    /**
     * Reports a website that is not an absolute URL.
     *
     * @param string $website
     *
     * @return self
     */
    public static function invalidWebsite(string $website): self
    {
        return new self(
            message: sprintf(
                'A company website must be a valid absolute URL, [%s] given.',
                $website,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_website',
            replace: ['website' => $website],
        );
    }

    /**
     * Reports a website longer than the website column accepts.
     *
     * @param int $length
     *
     * @return self
     */
    public static function websiteTooLong(int $length): self
    {
        return new self(
            message: sprintf(
                'A company website cannot exceed %d characters, %d given.',
                CompanyRules::MAX_WEBSITE_LENGTH,
                $length,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'website_too_long',
            replace: [
                'max' => (string) CompanyRules::MAX_WEBSITE_LENGTH,
                'given' => (string) $length,
            ],
        );
    }

    /**
     * Reports an industry longer than the industry column accepts.
     *
     * @param int $length
     *
     * @return self
     */
    public static function industryTooLong(int $length): self
    {
        return new self(
            message: sprintf(
                'A company industry cannot exceed %d characters, %d given.',
                CompanyRules::MAX_INDUSTRY_LENGTH,
                $length,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'industry_too_long',
            replace: [
                'max' => (string) CompanyRules::MAX_INDUSTRY_LENGTH,
                'given' => (string) $length,
            ],
        );
    }

    /**
     * Reports a phone number longer than the phone column accepts.
     *
     * @param int $length
     *
     * @return self
     */
    public static function phoneTooLong(int $length): self
    {
        return new self(
            message: sprintf(
                'A company phone number cannot exceed %d characters, %d given.',
                CompanyRules::MAX_PHONE_LENGTH,
                $length,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'phone_too_long',
            replace: [
                'max' => (string) CompanyRules::MAX_PHONE_LENGTH,
                'given' => (string) $length,
            ],
        );
    }

    /**
     * Reports a page size outside the bounds the list accepts.
     *
     * @param int $perPage
     *
     * @return self
     */
    public static function invalidPerPage(int $perPage): self
    {
        return new self(
            message: sprintf(
                'Page size must be between %d and %d, %d given.',
                CompanyRules::MIN_PER_PAGE,
                CompanyRules::MAX_PER_PAGE,
                $perPage,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_per_page',
            replace: [
                'min' => (string) CompanyRules::MIN_PER_PAGE,
                'max' => (string) CompanyRules::MAX_PER_PAGE,
                'given' => (string) $perPage,
            ],
        );
    }
}
