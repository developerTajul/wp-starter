<?php

declare(strict_types=1);

namespace Core\Contact\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when a write loses to the unique email index.
 *
 * The repository adapter translates the database's duplicate-key error into
 * this exception, so no framework exception crosses into the Service. The
 * Service catches it and reports the conflict as a failure Result.
 */
final class ContactDuplicateException extends RuntimeException
{
    /**
     * Translations for this exception live under this path.
     */
    public const TRANSLATION_PREFIX = 'contacts.errors.';

    /**
     * @param string $message Untranslated default wording.
     * @param string $translationKey
     */
    public function __construct(
        string $message = 'A contact with the same email already exists.',
        public readonly string $translationKey = self::TRANSLATION_PREFIX . 'duplicate',
    ) {
        parent::__construct($message);
    }
}
