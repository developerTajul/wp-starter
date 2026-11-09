<?php

declare(strict_types=1);

namespace Core\Pipeline\Infrastructure\Mappers;

use Core\Pipeline\Domain\Entities\PipelineEntity;
use Core\Pipeline\Domain\ValueObjects\PipelineStage;

/**
 * Maps model arrays to PipelineEntity and to the pipelines table's columns.
 */
final class PipelineEntityMapper
{
    /**
     * Builds a pure domain entity from a model array, with the stored stages
     * rebuilt as value objects carrying the ids the write assigned.
     *
     * @param array $data
     * @param array $stages
     *
     * @return PipelineEntity
     */
    public function toEntity(array $data, array $stages = []): PipelineEntity
    {
        return PipelineEntity::reconstitute(
            id: (int) $data['id'],
            name: (string) $data['name'],
            isDefault: (bool) $data['is_default'],
            stages: array_map($this->toStage(...), $stages),
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
        );
    }

    /**
     * Flattens the entity's own state onto the pipelines table's columns.
     *
     * @param PipelineEntity $pipeline
     *
     * @return array<string, mixed>
     */
    public function toPersistenceArray(PipelineEntity $pipeline): array
    {
        return [
            'name' => $pipeline->name,
            'is_default' => $pipeline->isDefault,
        ];
    }

    /**
     * Flattens one stage value object onto the deal_stages table's columns.
     * The stage is read-only state of the pipeline here, so persistence keys
     * are passed in rather than looked up on the value object.
     *
     * The timestamps are written here because the stages are inserted with the
     * query builder, which does not go through the model and so does not fill
     * them the way Eloquent's own save() would. Without this the columns stay
     * null and a screen printing a stage's dates shows nothing.
     *
     * @param PipelineStage $stage
     * @param int           $pipelineId
     *
     * @return array<string, mixed>
     */
    public function toStagePersistenceArray(PipelineStage $stage, int $pipelineId): array
    {
        $writtenAt = now();

        return [
            'pipeline_id' => $pipelineId,
            'name' => $stage->name,
            'order_position' => $stage->orderPosition,
            'probability' => $stage->probability,
            'created_at' => $writtenAt,
            'updated_at' => $writtenAt,
        ];
    }

    /**
     * Rebuilds a stage value object from a model array.
     *
     * @param array $data
     *
     * @return PipelineStage
     */
    private function toStage(array $data): PipelineStage
    {
        return PipelineStage::reconstitute(
            id: (int) $data['id'],
            name: (string) $data['name'],
            orderPosition: (int) $data['order_position'],
            probability: (int) $data['probability'],
        );
    }
}
