<?php

declare(strict_types=1);

namespace Core\Company\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when a write targets a company that no longer exists.
 *
 * The repository adapter raises this instead of a bare RuntimeException, so the
 * Service can report the missing row as an ordinary not-found outcome rather
 * than as a write failure. A bare RuntimeException cannot be told apart from a
 * genuine database error once it reaches the Service's fallback catch.
 */
final class CompanyNotFoundException extends RuntimeException
{
    /**
     * Translations for this exception live under this path.
     */
    public const TRANSLATION_PREFIX = 'companies.errors.';

    /**
     * @param int    $companyId
     * @param string $message Untranslated default wording.
     * @param string $translationKey
     */
    public function __construct(
        public readonly int $companyId,
        string $message = '',
        public readonly string $translationKey = self::TRANSLATION_PREFIX . 'company_not_found',
    ) {
        parent::__construct($message === ''
            ? sprintf('Company with ID %d no longer exists.', $companyId)
            : $message);
    }
}
