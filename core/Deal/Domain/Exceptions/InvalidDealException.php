<?php

declare(strict_types=1);

namespace Core\Deal\Domain\Exceptions;

use Core\Deal\Domain\DealRules;
use RuntimeException;

/**
 * Thrown when a deal would be left in an invalid state.
 *
 * Carries the translation key for the rule alongside its message. The key is
 * what delivery translates; the message is the untranslated default, so a
 * caller with no translator still gets something readable and the domain
 * never holds presentation wording.
 */
final class InvalidDealException extends RuntimeException
{
    /**
     * Translations for this exception live under this path, followed by the
     * specific rule (e.g. deals.errors.empty_title).
     */
    public const TRANSLATION_PREFIX = 'deals.errors.';

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
     * Reports a missing or blank title.
     *
     * @return self
     */
    public static function emptyTitle(): self
    {
        return new self(
            message: 'A deal must carry a title.',
            translationKey: self::TRANSLATION_PREFIX . 'empty_title',
        );
    }

    /**
     * Reports a title longer than the title column accepts.
     *
     * @param int $length
     *
     * @return self
     */
    public static function titleTooLong(int $length): self
    {
        return new self(
            message: sprintf(
                'A deal title cannot exceed %d characters, %d given.',
                DealRules::MAX_TITLE_LENGTH,
                $length,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'title_too_long',
            replace: ['max' => (string) DealRules::MAX_TITLE_LENGTH, 'given' => (string) $length],
        );
    }

    /**
     * Reports an amount that does not fit the amount column.
     *
     * @param string $amount
     *
     * @return self
     */
    public static function invalidAmount(string $amount): self
    {
        return new self(
            message: sprintf(
                'A deal amount must be between %s and %s, [%s] given.',
                DealRules::MIN_AMOUNT,
                DealRules::MAX_AMOUNT,
                $amount,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_amount',
            replace: [
                'min' => DealRules::MIN_AMOUNT,
                'max' => DealRules::MAX_AMOUNT,
                'given' => $amount,
            ],
        );
    }

    /**
     * Reports a currency code that is not the three letters the column holds.
     *
     * @param string $currency
     *
     * @return self
     */
    public static function invalidCurrency(string $currency): self
    {
        return new self(
            message: sprintf(
                'A deal currency must be a %d-letter code, [%s] given.',
                DealRules::CURRENCY_LENGTH,
                $currency,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_currency',
            replace: ['given' => $currency],
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
        );
    }

    /**
     * Reports a contact ID that cannot identify a contact.
     *
     * @param int $contactId
     *
     * @return self
     */
    public static function invalidContactId(int $contactId): self
    {
        return new self(
            message: sprintf(
                'A linked contact ID must be a positive integer, %d given.',
                $contactId,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_contact_id',
        );
    }

    /**
     * Reports a pipeline ID that cannot identify a pipeline.
     *
     * @param int $pipelineId
     *
     * @return self
     */
    public static function invalidPipelineId(int $pipelineId): self
    {
        return new self(
            message: sprintf(
                'A pipeline ID must be a positive integer, %d given.',
                $pipelineId,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_pipeline_id',
        );
    }

    /**
     * Reports a stage ID that cannot identify a stage.
     *
     * @param int $dealStageId
     *
     * @return self
     */
    public static function invalidStageId(int $dealStageId): self
    {
        return new self(
            message: sprintf(
                'A deal stage ID must be a positive integer, %d given.',
                $dealStageId,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_stage_id',
        );
    }

    /**
     * Reports a page size outside the range the deal list accepts.
     *
     * @param int $perPage
     *
     * @return self
     */
    public static function invalidPerPage(int $perPage): self
    {
        return new self(
            message: sprintf(
                'A deal page size must be between %d and %d, %d given.',
                DealRules::MIN_PER_PAGE,
                DealRules::MAX_PER_PAGE,
                $perPage,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_per_page',
            replace: [
                'min' => (string) DealRules::MIN_PER_PAGE,
                'max' => (string) DealRules::MAX_PER_PAGE,
                'given' => (string) $perPage,
            ],
        );
    }

    /**
     * Reports a close date that is not a date.
     *
     * @param string $closeDate
     *
     * @return self
     */
    public static function invalidCloseDate(string $closeDate): self
    {
        return new self(
            message: sprintf(
                'A deal close date must be a date in Y-m-d form, [%s] given.',
                $closeDate,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_close_date',
        );
    }

    /**
     * Reports a stage that does not belong to the deal's pipeline.
     *
     * @param int $dealStageId
     * @param int $pipelineId
     *
     * @return self
     */
    public static function stageNotInPipeline(int $dealStageId, int $pipelineId): self
    {
        return new self(
            message: sprintf(
                'The stage %d does not belong to pipeline %d, so the deal cannot sit in it.',
                $dealStageId,
                $pipelineId,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'stage_not_in_pipeline',
        );
    }
}
