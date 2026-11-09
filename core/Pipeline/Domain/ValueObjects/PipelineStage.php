<?php

declare(strict_types=1);

namespace Core\Pipeline\Domain\ValueObjects;

use Core\Pipeline\Domain\Exceptions\InvalidPipelineException;
use Core\Pipeline\Domain\PipelineRules;

/**
 * A single step of a pipeline: the position it sits at and the probability a
 * deal at that step is expected to close with.
 */
final readonly class PipelineStage
{
    /**
     * @param int|null $id
     * @param int      $orderPosition
     * @param string   $name
     * @param int      $probability
     */
    private function __construct(
        public ?int $id,
        public int $orderPosition,
        public string $name,
        public int $probability,
    ) {}

    /**
     * Builds a stage, guarding the values a stage may carry.
     *
     * @param string $name
     * @param int    $orderPosition
     * @param int    $probability
     *
     * @return self
     *
     * @throws InvalidPipelineException When a value would break a stage invariant.
     */
    public static function create(string $name, int $orderPosition, int $probability): self
    {
        self::assertNameIsUsable($name);
        self::assertOrderPositionIsUsable($orderPosition);
        self::assertProbabilityIsUsable($probability);

        return new self(
            id: null,
            orderPosition: $orderPosition,
            name: $name,
            probability: $probability,
        );
    }

    /**
     * Rebuilds a stage from persisted state, carrying the id the write assigned.
     * The stored fields are taken as they are, so a row written before a rule
     * existed still loads.
     *
     * @param int    $id
     * @param string $name
     * @param int    $orderPosition
     * @param int    $probability
     *
     * @return self
     */
    public static function reconstitute(int $id, string $name, int $orderPosition, int $probability): self
    {
        return new self(
            id: $id,
            orderPosition: $orderPosition,
            name: $name,
            probability: $probability,
        );
    }

    /**
     * Whether another stage sits at the same slot in the pipeline.
     *
     * @param self $other
     *
     * @return bool
     */
    public function hasSameOrderPosition(self $other): bool
    {
        return $this->orderPosition === $other->orderPosition;
    }

    /**
     * Whether another stage carries the same name. Compared case-insensitively,
     * because the stages table's unique index does not see case either.
     *
     * @param self $other
     *
     * @return bool
     */
    public function hasSameName(self $other): bool
    {
        return mb_strtolower($this->name) === mb_strtolower($other->name);
    }

    /**
     * Guards the name invariant: a stage without a name cannot be told apart
     * from any other step of the pipeline.
     *
     * @param string $name
     *
     * @return void
     *
     * @throws InvalidPipelineException When the name is blank or too long.
     */
    private static function assertNameIsUsable(string $name): void
    {
        if (trim($name) === '') {
            throw InvalidPipelineException::emptyStageName();
        }

        if (mb_strlen($name) > PipelineRules::MAX_STAGE_NAME_LENGTH) {
            throw InvalidPipelineException::stageNameTooLong(mb_strlen($name));
        }
    }

    /**
     * Guards the order position invariant: positions start at the first slot
     * and cannot go below it.
     *
     * @param int $orderPosition
     *
     * @return void
     *
     * @throws InvalidPipelineException When the position sits below the first slot.
     */
    private static function assertOrderPositionIsUsable(int $orderPosition): void
    {
        if ($orderPosition < PipelineRules::MIN_ORDER_POSITION) {
            throw InvalidPipelineException::invalidOrderPosition($orderPosition);
        }
    }

    /**
     * Guards the probability invariant: a probability is a percentage.
     *
     * @param int $probability
     *
     * @return void
     *
     * @throws InvalidPipelineException When the probability sits outside the percentage range.
     */
    private static function assertProbabilityIsUsable(int $probability): void
    {
        if ($probability < PipelineRules::MIN_PROBABILITY || $probability > PipelineRules::MAX_PROBABILITY) {
            throw InvalidPipelineException::invalidProbability($probability);
        }
    }
}
