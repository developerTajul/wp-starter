<?php

declare(strict_types=1);

namespace Core\Pipeline\Domain\Entities;

use Core\Pipeline\Domain\Exceptions\InvalidPipelineException;
use Core\Pipeline\Domain\PipelineRules;
use Core\Pipeline\Domain\ValueObjects\PipelineStage;

/**
 * Domain entity representing a pipeline and the stages it is made of.
 */
readonly class PipelineEntity
{
    /**
     * @param int|null                $id
     * @param string                  $name
     * @param bool                    $isDefault
     * @param array<int, PipelineStage> $stages
     * @param string|null             $createdAt
     * @param string|null             $updatedAt
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public bool $isDefault,
        public array $stages = [],
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}

    /**
     * Builds a new, unsaved pipeline from the stages it is made of. The stages
     * are validated as a set before the pipeline is built, so a rejected stage
     * leaves no half-built pipeline behind.
     *
     * @param string                  $name
     * @param bool                    $isDefault
     * @param array<int, PipelineStage> $stages
     *
     * @return self
     *
     * @throws InvalidPipelineException When a field or the stage set would break an invariant.
     */
    public static function create(string $name, bool $isDefault, array $stages): self
    {
        self::assertNameIsUsable($name);
        self::assertStagesAreUsable($stages);

        return new self(
            id: null,
            name: $name,
            isDefault: $isDefault,
            stages: $stages,
        );
    }

    /**
     * Rebuilds a pipeline from persisted state. The stored fields are taken as
     * they are, so a row written before a rule existed still loads.
     *
     * @param int                     $id
     * @param string                  $name
     * @param bool                    $isDefault
     * @param array<int, PipelineStage> $stages
     * @param string|null             $createdAt
     * @param string|null             $updatedAt
     *
     * @return self
     */
    public static function reconstitute(
        int $id,
        string $name,
        bool $isDefault,
        array $stages = [],
        ?string $createdAt = null,
        ?string $updatedAt = null,
    ): self {
        return new self(
            id: $id,
            name: $name,
            isDefault: $isDefault,
            stages: $stages,
            createdAt: $createdAt,
            updatedAt: $updatedAt,
        );
    }

    /**
     * Applies an edit and returns the updated pipeline. An edit replaces the
     * whole pipeline: the name, the default flag and the stage set all arrive
     * together, so there is no partial-update state to merge against.
     *
     * The whole payload is validated before anything is applied, so a rejected
     * edit leaves the pipeline untouched.
     *
     * @param string                  $name
     * @param bool                    $isDefault
     * @param array<int, PipelineStage> $stages
     *
     * @return self
     *
     * @throws InvalidPipelineException When a field or the stage set would break an invariant.
     */
    public function applyChanges(string $name, bool $isDefault, array $stages): self
    {
        self::assertNameIsUsable($name);
        self::assertStagesAreUsable($stages);

        return new self(
            id: $this->id,
            name: $name,
            isDefault: $isDefault,
            stages: $stages,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }

    /**
     * The stages in the order they are worked through.
     *
     * The stored order is whatever the read returned, so the ordering is
     * decided here instead of relying on it.
     *
     * @return array<int, PipelineStage>
     */
    public function orderedStages(): array
    {
        $stages = $this->stages;

        usort(
            $stages,
            static fn(PipelineStage $left, PipelineStage $right): int => $left->orderPosition <=> $right->orderPosition,
        );

        return $stages;
    }

    /**
     * Guards the name invariant: a pipeline without a name cannot be told
     * apart from any other.
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
            throw InvalidPipelineException::emptyName();
        }

        if (mb_strlen($name) > PipelineRules::MAX_NAME_LENGTH) {
            throw InvalidPipelineException::nameTooLong(mb_strlen($name));
        }
    }

    /**
     * Guards the stage set as a whole: a pipeline is a sequence of steps, so it
     * needs at least one, no two stages may share a slot, and no two may share
     * a name. The stages table backs the name rule with a unique index on
     * (pipeline_id, name), so a duplicated name would fail at the write.
     *
     * @param array<int, PipelineStage> $stages
     *
     * @return void
     *
     * @throws InvalidPipelineException When the set is empty or holds a duplicate.
     */
    private static function assertStagesAreUsable(array $stages): void
    {
        if ($stages === []) {
            throw InvalidPipelineException::noStages();
        }

        $checkedStages = [];

        foreach ($stages as $stage) {
            foreach ($checkedStages as $checkedStage) {
                if ($stage->hasSameOrderPosition($checkedStage)) {
                    throw InvalidPipelineException::duplicateOrderPosition($stage->orderPosition);
                }

                if ($stage->hasSameName($checkedStage)) {
                    throw InvalidPipelineException::duplicateStageName($stage->name);
                }
            }

            $checkedStages[] = $stage;
        }
    }
}
