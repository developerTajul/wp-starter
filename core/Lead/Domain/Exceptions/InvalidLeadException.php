<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Exceptions;

use Core\Lead\Domain\Rules\LeadRules;
use Core\Lead\Domain\Enums\LeadStatus;
use RuntimeException;

/**
 * Raised when lead input or criteria breaks a domain rule.
 *
 * Carries the translation key for the rule alongside its message. The key is
 * what delivery translates; the message is the untranslated default, so a
 * caller with no translator still gets something readable and the domain
 * never holds presentation wording.
 */
final class InvalidLeadException extends RuntimeException
{
    /**
     * Translations for this exception live under this path, followed by the
     * specific rule (e.g. leads.errors.same_status).
     */
    public const TRANSLATION_PREFIX = 'leads.errors.';

    /**
     * @param string      $message    Untranslated default wording.
     * @param string      $translationKey
     * @param array<string, string> $replace Replacement values for the key.
     */
    public function __construct(
        string $message,
        public readonly string $translationKey,
        public readonly array $replace = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Builds the exception for a page size outside its bounds.
     *
     * @param int $perPage
     *
     * @return self
     */
    public static function invalidPerPage(int $perPage): self
    {
        return new self(
            message: sprintf(
                'Page size must be between %d and %d, got %d.',
                LeadRules::MIN_PER_PAGE,
                LeadRules::MAX_PER_PAGE,
                $perPage,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_per_page',
            replace: [
                'min' => (string) LeadRules::MIN_PER_PAGE,
                'max' => (string) LeadRules::MAX_PER_PAGE,
                'given' => (string) $perPage,
            ],
        );
    }

    /**
     * Builds the exception for a move to the status the lead already holds.
     *
     * @param LeadStatus $from
     *
     * @return self
     */
    public static function sameStatus(LeadStatus $from): self
    {
        return new self(
            message: sprintf('Lead is already %s.', $from->value),
            translationKey: self::TRANSLATION_PREFIX . 'same_status',
            replace: ['status' => $from->translationKey()],
        );
    }

    /**
     * Builds the exception for an owner id that cannot name a user.
     *
     * @param int $userId
     *
     * @return self
     */
    public static function invalidAssignee(int $userId): self
    {
        return new self(
            message: sprintf('A lead cannot be assigned to user %d.', $userId),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_assignee',
            replace: ['id' => (string) $userId],
        );
    }

    /**
     * Builds the exception for a transition the rules do not allow.
     *
     * @param LeadStatus $from
     * @param LeadStatus $to
     *
     * @return self
     */
    public static function transitionNotAllowed(LeadStatus $from, LeadStatus $to): self
    {
        return new self(
            message: sprintf('A lead cannot move from %s to %s.', $from->value, $to->value),
            translationKey: self::TRANSLATION_PREFIX . 'transition_not_allowed',
            replace: [
                'from' => $from->translationKey(),
                'to' => $to->translationKey(),
            ],
        );
    }

    /**
     * Builds the exception for a lead left without a name. The column is NOT
     * NULL, so a blank name is a value the table would reject.
     *
     * @return self
     */
    public static function emptyName(): self
    {
        return new self(
            message: 'A lead must carry a name.',
            translationKey: self::TRANSLATION_PREFIX . 'name_empty',
        );
    }

    /**
     * Builds the exception for a name wider than its column.
     *
     * @param int $length
     *
     * @return self
     */
    public static function nameTooLong(int $length): self
    {
        return new self(
            message: sprintf('A lead name may be at most %d characters, got %d.', LeadRules::MAX_NAME_LENGTH, $length),
            translationKey: self::TRANSLATION_PREFIX . 'name_too_long',
            replace: ['max' => (string) LeadRules::MAX_NAME_LENGTH],
        );
    }

    /**
     * Builds the exception for converting a lead that carries no email.
     *
     * The contacts table makes the email column NOT NULL, and a conversion
     * creates a contact, so a lead without one has nowhere to go. Mirrors that
     * constraint rather than inventing a rule: the refusal is the column.
     *
     * @return self
     */
    public static function conversionNeedsEmail(): self
    {
        return new self(
            message: 'A lead needs an email address before it can be converted.',
            translationKey: self::TRANSLATION_PREFIX . 'conversion_needs_email',
        );
    }

    /**
     * Builds the exception for converting a lead that was never stored.
     *
     * The conversion raises an event naming the lead's id, so a row that does
     * not exist yet has no id to name. Reached only by misuse of the entity -
     * the services convert what they loaded - but raised as a domain refusal so
     * the reason reaches the caller translatable instead of as a bare
     * runtime error.
     *
     * @return self
     */
    public static function conversionNeedsStoredLead(): self
    {
        return new self(
            message: 'A lead must be stored before it can be converted.',
            translationKey: self::TRANSLATION_PREFIX . 'conversion_needs_stored_lead',
        );
    }
}
