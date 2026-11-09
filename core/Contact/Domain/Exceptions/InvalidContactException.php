<?php

declare(strict_types=1);

namespace Core\Contact\Domain\Exceptions;

use Core\Contact\Domain\Rules\ContactRules;
use RuntimeException;

/**
 * Thrown when a contact would be left in an invalid state.
 *
 * Carries the translation key for the rule alongside its message. The key is
 * what delivery translates; the message is the untranslated default, so a
 * caller with no translator still gets something readable and the domain
 * never holds presentation wording.
 */
final class InvalidContactException extends RuntimeException
{
    /**
     * Translations for this exception live under this path, followed by the
     * specific rule (e.g. contacts.errors.empty_name).
     */
    public const TRANSLATION_PREFIX = 'contacts.errors.';

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
            message: 'A contact must carry a name.',
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
                'A contact name cannot exceed %d characters, %d given.',
                ContactRules::MAX_NAME_LENGTH,
                $length,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'name_too_long',
            replace: [
                'max' => (string) ContactRules::MAX_NAME_LENGTH,
                'given' => (string) $length,
            ],
        );
    }

    /**
     * Reports a missing or blank email address.
     *
     * @return self
     */
    public static function emptyEmail(): self
    {
        return new self(
            message: 'A contact must carry an email address.',
            translationKey: self::TRANSLATION_PREFIX . 'empty_email',
        );
    }

    /**
     * Reports an email that is not a usable address.
     *
     * @param string $email
     *
     * @return self
     */
    public static function invalidEmail(string $email): self
    {
        return new self(
            message: sprintf(
                'A contact email must be a valid email address, [%s] given.',
                $email,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_email',
            replace: ['email' => $email],
        );
    }

    /**
     * Reports an email longer than the email column accepts.
     *
     * @param int $length
     *
     * @return self
     */
    public static function emailTooLong(int $length): self
    {
        return new self(
            message: sprintf(
                'A contact email cannot exceed %d characters, %d given.',
                ContactRules::MAX_EMAIL_LENGTH,
                $length,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'email_too_long',
            replace: [
                'max' => (string) ContactRules::MAX_EMAIL_LENGTH,
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
                'A contact phone number cannot exceed %d characters, %d given.',
                ContactRules::MAX_PHONE_LENGTH,
                $length,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'phone_too_long',
            replace: [
                'max' => (string) ContactRules::MAX_PHONE_LENGTH,
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
                ContactRules::MIN_PER_PAGE,
                ContactRules::MAX_PER_PAGE,
                $perPage,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_per_page',
            replace: [
                'min' => (string) ContactRules::MIN_PER_PAGE,
                'max' => (string) ContactRules::MAX_PER_PAGE,
                'given' => (string) $perPage,
            ],
        );
    }

    /**
     * Reports a company ID that cannot identify a company.
     *
     * @param int $companyId
     *
     * @return self
     */
    public static function invalidCompanyId(int $companyId): self
    {
        return new self(
            message: sprintf(
                'A linked company ID must be a positive integer, %d given.',
                $companyId,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_company_id',
            replace: ['company_id' => (string) $companyId],
        );
    }

    /**
     * Reports a job title longer than the pivot column accepts.
     *
     * @param int $length
     *
     * @return self
     */
    public static function jobTitleTooLong(int $length): self
    {
        return new self(
            message: sprintf(
                'A contact job title cannot exceed %d characters, %d given.',
                ContactRules::MAX_JOB_TITLE_LENGTH,
                $length,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'job_title_too_long',
            replace: [
                'max' => (string) ContactRules::MAX_JOB_TITLE_LENGTH,
                'given' => (string) $length,
            ],
        );
    }

    /**
     * Reports a company change that both attaches a company and drops the
     * stored one. The two cannot both happen, so the payload is refused.
     *
     * @return self
     */
    public static function contradictoryCompanyChange(): self
    {
        return new self(
            message: 'A contact cannot be attached to a company and detached from its company at the same time.',
            translationKey: self::TRANSLATION_PREFIX . 'contradictory_company_change',
        );
    }
}
