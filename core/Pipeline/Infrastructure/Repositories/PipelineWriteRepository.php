<?php

declare(strict_types=1);

namespace Core\Pipeline\Infrastructure\Repositories;

use App\Models\DealStage;
use App\Models\Pipeline;
use Core\Pipeline\Domain\Contracts\PipelineRepositoryContract;
use Core\Pipeline\Domain\Entities\PipelineEntity;
use Core\Pipeline\Domain\Exceptions\PipelineDuplicateException;
use Core\Pipeline\Domain\ValueObjects\PipelineStage;
use Core\Pipeline\Infrastructure\DataObjects\StoredPipelineData;
use Core\Pipeline\Infrastructure\Mappers\PipelineEntityMapper;
use Core\Shared\Infrastructure\Repositories\BaseRepository;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Eloquent-backed writes for pipelines and their stages. Results are mapped to
 * Domain Entities before they leave.
 *
 * @implements PipelineRepositoryContract
 */
final class PipelineWriteRepository extends BaseRepository implements PipelineRepositoryContract
{
    /** SQLSTATE for an integrity constraint violation, a unique index rejecting the row. */
    private const SQLSTATE_INTEGRITY_VIOLATION = '23000';

    /**
     * @param Pipeline             $model
     * @param PipelineEntityMapper $mapper
     */
    public function __construct(
        Pipeline $model,
        private readonly PipelineEntityMapper $mapper,
    ) {
        parent::__construct($model);
    }

    /**
     * Inserts a pipeline and its stages, then reads the graph back. The caller
     * owns the transaction boundary, which is what rolls back a partial write.
     *
     * @param PipelineEntity $pipeline
     *
     * @return PipelineEntity The stored pipeline with its stages.
     *
     * @throws RuntimeException             When the pipeline row could not be written.
     * @throws PipelineDuplicateException   When a unique index rejects a stage row.
     */
    public function create(PipelineEntity $pipeline): PipelineEntity
    {
        $pipelineModel = $this->createRecord($this->mapper->toPersistenceArray($pipeline));

        if (! $pipelineModel instanceof Pipeline) {
            throw new RuntimeException('The pipeline row could not be written.');
        }

        $this->writeStages($pipeline->orderedStages(), $pipelineModel->id);

        if ($pipeline->isDefault) {
            $this->clearDefaultExcept($pipelineModel->id);
        }

        return $this->mapper->toEntity(
            data: $pipelineModel->refresh()->toArray(),
            stages: $this->readStoredStages($pipelineModel->id),
        );
    }

    /**
     * Reads a pipeline together with its stages while holding a row lock, so a
     * concurrent write cannot slip between the read and the save.
     *
     * @param int $id
     *
     * @return PipelineEntity|null Null when no pipeline carries the id.
     */
    public function findByIdForUpdate(int $id): ?PipelineEntity
    {
        $pipelineModel = $this->findRecordForUpdate($id);

        if (! $pipelineModel instanceof Pipeline) {
            return null;
        }

        return $this->mapper->toEntity(
            data: $pipelineModel->toArray(),
            stages: $this->readStoredStages($id),
        );
    }

    /**
     * Writes a pipeline's current state back to its row and replaces its stage
     * set. The caller owns the transaction boundary, which is what restores the
     * stored graph when a stage is rejected.
     *
     * @param PipelineEntity $pipeline
     *
     * @return PipelineEntity The stored pipeline with its stages.
     *
     * @throws RuntimeException           When the entity has no ID, or the row no longer exists.
     * @throws PipelineDuplicateException When a unique index rejects a stage row.
     */
    public function update(PipelineEntity $pipeline): PipelineEntity
    {
        if ($pipeline->id === null) {
            throw new RuntimeException('Cannot update a pipeline that has no ID.');
        }

        $pipelineModel = $this->findRecord($pipeline->id);

        if (! $pipelineModel instanceof Pipeline) {
            throw new RuntimeException(sprintf(
                'Pipeline with ID %d no longer exists.',
                $pipeline->id,
            ));
        }

        $pipelineModel->fill($this->mapper->toPersistenceArray($pipeline));
        $pipelineModel->save();

        DealStage::query()->where('pipeline_id', $pipeline->id)->delete();

        $this->writeStages($pipeline->orderedStages(), $pipeline->id);

        if ($pipeline->isDefault) {
            $this->clearDefaultExcept($pipeline->id);
        }

        return $this->mapper->toEntity(
            data: $pipelineModel->refresh()->toArray(),
            stages: $this->readStoredStages($pipeline->id),
        );
    }

    /**
     * Clears the default flag on every pipeline except the one named.
     *
     * The rule spans rows, so no single entity can hold it: a second default
     * makes the Deal module's default-pipeline lookup arbitrary. Runs inside the
     * caller's transaction, so the flag is never ambiguous at a commit.
     *
     * @param int $keepId The pipeline that keeps (or takes) the flag.
     *
     * @return void
     */
    private function clearDefaultExcept(int $keepId): void
    {
        Pipeline::query()
            ->where('is_default', true)
            ->where('id', '!=', $keepId)
            ->update(['is_default' => false, 'updated_at' => now()]);
    }

    /**
     * Writes one deal_stages row per stage. The database assigns the stage ids,
     * so they are not part of the insert.
     *
     * @param array<int, PipelineStage> $stages
     * @param int                       $pipelineId
     *
     * @return void
     *
     * @throws RuntimeException           When a stage row could not be written.
     * @throws PipelineDuplicateException When the unique index on (pipeline_id, name) rejects a row.
     */
    private function writeStages(array $stages, int $pipelineId): void
    {
        foreach ($stages as $stage) {
            try {
                $isWritten = DealStage::query()->insert(
                    $this->mapper->toStagePersistenceArray($stage, $pipelineId),
                );
            } catch (QueryException $exception) {
                throw $this->translateWriteFailure($exception, $stage);
            }

            if (! $isWritten) {
                throw new RuntimeException(sprintf(
                    'The stage [%s] could not be written for pipeline %d.',
                    $stage->name,
                    $pipelineId,
                ));
            }
        }
    }

    /**
     * Turns a failed stage write into the exception Core is allowed to see.
     *
     * A unique-index violation becomes the module's conflict exception. Any other
     * database error keeps its original type, so a caller cannot mistake an
     * outage for "already exists".
     *
     * @param QueryException $exception
     * @param PipelineStage  $stage
     *
     * @return \Throwable
     */
    private function translateWriteFailure(QueryException $exception, PipelineStage $stage): \Throwable
    {
        if ((string) $exception->getCode() === self::SQLSTATE_INTEGRITY_VIOLATION) {
            return PipelineDuplicateException::stageNameTaken($stage->name);
        }

        return $exception;
    }

    /**
     * Reads the pipeline's stages as ordered plain rows, so the mapper receives
     * columns rather than Eloquent models.
     *
     * @param int $pipelineId
     *
     * @return array<int, array<string, mixed>>
     */
    private function readStoredStages(int $pipelineId): array
    {
        return DealStage::query()
            ->where('pipeline_id', $pipelineId)
            ->orderBy('order_position')
            ->get()
            ->map(fn(DealStage $stage) => $stage->toArray())
            ->all();
    }
}
