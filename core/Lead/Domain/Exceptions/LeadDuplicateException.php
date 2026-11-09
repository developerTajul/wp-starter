<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when a write loses to the unique email or phone index.
 *
 * The repository adapter translates the database's duplicate-key error into
 * this exception, so no framework exception crosses into the Service. The
 * Service catches it and reports the conflict as a failure Result.
 */
final class LeadDuplicateException extends RuntimeException
{
    /**
     * Translations for this exception live under this path.
     */
    public const TRANSLATION_PREFIX = 'leads.errors.';

    /**
     * @param string $message Untranslated default wording.
     * @param string $translationKey
     */
    public function __construct(
        string $message = 'A lead with the same email or phone already exists.',
        public readonly string $translationKey = self::TRANSLATION_PREFIX . 'duplicate',
    ) {
        parent::__construct($message);
    }
}
