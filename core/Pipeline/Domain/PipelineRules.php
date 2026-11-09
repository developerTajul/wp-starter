<?php

declare(strict_types=1);

namespace Core\Pipeline\Domain;

/**
 * Holds the pipeline module's shared numeric rules.
 */
final class PipelineRules
{
    public const MAX_NAME_LENGTH = 255;

    public const MAX_STAGE_NAME_LENGTH = 255;

    public const MIN_ORDER_POSITION = 1;

    public const MIN_PROBABILITY = 0;

    public const MAX_PROBABILITY = 100;
}
