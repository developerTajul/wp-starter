<?php

declare(strict_types=1);

namespace Core\Lead\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when building the records a converted lead becomes fails partway.
 *
 * The lead itself is valid; a company, contact or deal creation it depends on
 * was rejected. The failing module's own error code is carried through, so the
 * reported failure still names the real reason instead of collapsing every
 * downstream rejection into one generic outcome.
 */
final class LeadConversionRecordsException extends RuntimeException
{
    /**
     * Translations for this exception live under this path.
     */
    public const TRANSLATION_PREFIX = 'leads.errors.';

    /**
     * @param string      $message            What could not be built.
     * @param string      $translationKey     The wording the client reads.
     * @param string|null $downstreamErrorCode The failing module's own error key.
     */
    public function __construct(
        string $message,
        public readonly string $translationKey = self::TRANSLATION_PREFIX . 'conversion_records_failed',
        public readonly ?string $downstreamErrorCode = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Builds the exception for a record whose own service rejected it.
     *
     * Takes plain values rather than the failure object it came from: the
     * Domain does not reference Application types, so the caller unwraps the
     * result before raising this.
     *
     * @param string      $record    The record that could not be built.
     * @param string      $reason    The failing service's own message.
     * @param string|null $errorCode The failing service's own error key.
     *
     * @return self
     */
    public static function downstreamFailed(string $record, string $reason, ?string $errorCode): self
    {
        return new self(
            message: sprintf('%s creation failed: %s', $record, $reason),
            downstreamErrorCode: $errorCode,
        );
    }
}
