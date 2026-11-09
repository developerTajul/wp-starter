<?php

declare(strict_types=1);

namespace Core\Pipeline\Domain\Exceptions;

use Core\Pipeline\Domain\PipelineRules;
use RuntimeException;

/**
 * Thrown when a pipeline would be left in an invalid state.
 *
 * Carries the translation key for the rule alongside its message. The key is
 * what delivery translates; the message is the untranslated default, so a
 * caller with no translator still gets something readable and the domain
 * never holds presentation wording.
 */
final class InvalidPipelineException extends RuntimeException
{
    /**
     * Translations for this exception live under this path, followed by the
     * specific rule (e.g. pipelines.errors.empty_name).
     */
    public const TRANSLATION_PREFIX = 'pipelines.errors.';

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
            message: 'A pipeline must carry a name.',
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
                'A pipeline name cannot exceed %d characters, %d given.',
                PipelineRules::MAX_NAME_LENGTH,
                $length,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'name_too_long',
            replace: [
                'max' => (string) PipelineRules::MAX_NAME_LENGTH,
                'given' => (string) $length,
            ],
        );
    }

    /**
     * Reports a pipeline built without any stage.
     *
     * @return self
     */
    public static function noStages(): self
    {
        return new self(
            message: 'A pipeline must carry at least one stage.',
            translationKey: self::TRANSLATION_PREFIX . 'no_stages',
        );
    }

    /**
     * Reports a stage with a missing or blank name.
     *
     * @return self
     */
    public static function emptyStageName(): self
    {
        return new self(
            message: 'A stage must carry a name.',
            translationKey: self::TRANSLATION_PREFIX . 'empty_stage_name',
        );
    }

    /**
     * Reports a stage name longer than the name column accepts.
     *
     * @param int $length
     *
     * @return self
     */
    public static function stageNameTooLong(int $length): self
    {
        return new self(
            message: sprintf(
                'A stage name cannot exceed %d characters, %d given.',
                PipelineRules::MAX_STAGE_NAME_LENGTH,
                $length,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'stage_name_too_long',
            replace: [
                'max' => (string) PipelineRules::MAX_STAGE_NAME_LENGTH,
                'given' => (string) $length,
            ],
        );
    }

    /**
     * Reports an order position below the first slot.
     *
     * @param int $orderPosition
     *
     * @return self
     */
    public static function invalidOrderPosition(int $orderPosition): self
    {
        return new self(
            message: sprintf(
                'A stage order position must be at least %d, %d given.',
                PipelineRules::MIN_ORDER_POSITION,
                $orderPosition,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_order_position',
            replace: [
                'min' => (string) PipelineRules::MIN_ORDER_POSITION,
                'given' => (string) $orderPosition,
            ],
        );
    }

    /**
     * Reports a probability outside the percentage range.
     *
     * @param int $probability
     *
     * @return self
     */
    public static function invalidProbability(int $probability): self
    {
        return new self(
            message: sprintf(
                'A stage probability must be between %d and %d, %d given.',
                PipelineRules::MIN_PROBABILITY,
                PipelineRules::MAX_PROBABILITY,
                $probability,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'invalid_probability',
            replace: [
                'min' => (string) PipelineRules::MIN_PROBABILITY,
                'max' => (string) PipelineRules::MAX_PROBABILITY,
                'given' => (string) $probability,
            ],
        );
    }

    /**
     * Reports two stages sharing a name. The stages table is unique on
     * (pipeline_id, name), so the pair cannot both be written.
     *
     * @param string $stageName
     *
     * @return self
     */
    public static function duplicateStageName(string $stageName): self
    {
        return new self(
            message: sprintf(
                'A pipeline cannot hold two stages named [%s].',
                $stageName,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'duplicate_stage_name',
            replace: ['stage' => $stageName],
        );
    }

    /**
     * Reports two stages sharing an order position, which would leave their
     * order undefined.
     *
     * @param int $orderPosition
     *
     * @return self
     */
    public static function duplicateOrderPosition(int $orderPosition): self
    {
        return new self(
            message: sprintf(
                'A pipeline cannot hold two stages at order position %d.',
                $orderPosition,
            ),
            translationKey: self::TRANSLATION_PREFIX . 'duplicate_order_position',
            replace: ['position' => (string) $orderPosition],
        );
    }
}
