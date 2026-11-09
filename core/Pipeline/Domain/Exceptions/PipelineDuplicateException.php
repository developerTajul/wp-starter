<?php

declare(strict_types=1);

namespace Core\Pipeline\Domain\Exceptions;

use RuntimeException;

/**
 * Raised when a write loses to one of the pipeline module's unique indexes.
 *
 * The repository adapter translates the database's duplicate-key error into
 * this exception, so no framework exception crosses into the Service. The
 * Service catches it and reports the conflict as a failure Result.
 */
final class PipelineDuplicateException extends RuntimeException
{
    /**
     * Translations for this exception live under this path.
     */
    public const TRANSLATION_PREFIX = 'pipelines.errors.';

    /**
     * @param string $message        Untranslated default wording.
     * @param string $translationKey
     */
    public function __construct(
        string $message = 'The pipeline conflicts with an existing row.',
        public readonly string $translationKey = self::TRANSLATION_PREFIX . 'duplicate',
    ) {
        parent::__construct($message);
    }

    /**
     * Reports two stages of one pipeline sharing a name. The stages table is
     * unique on (pipeline_id, name), so the pair cannot both be written.
     *
     * @param string $stageName
     *
     * @return self
     */
    public static function stageNameTaken(string $stageName): self
    {
        return new self(
            message: sprintf('The pipeline already holds a stage named [%s].', $stageName),
            translationKey: self::TRANSLATION_PREFIX . 'stage_name_taken',
        );
    }
}
