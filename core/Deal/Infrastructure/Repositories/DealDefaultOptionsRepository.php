<?php

declare(strict_types=1);

namespace Core\Deal\Infrastructure\Repositories;

use App\Models\DealStage;
use App\Models\Pipeline;
use Core\Deal\Application\Contracts\DealDefaultOptionsContract;

/**
 * Reads the pipeline and stage a new deal falls back to.
 *
 * @implements DealDefaultOptionsContract
 */
final class DealDefaultOptionsRepository implements DealDefaultOptionsContract
{
    /**
     * The pipeline a new deal belongs to.
     *
     * @return int|null
     */
    public function getDefaultPipelineId(): ?int
    {
        $pipelineId = Pipeline::query()
            ->where('is_default', true)
            ->orderBy('id')
            ->value('id');

        if ($pipelineId === null) {
            return null;
        }

        return (int) $pipelineId;
    }

    /**
     * The stage a freshly created deal opens in.
     *
     * @param int    $pipelineId
     * @param string $stageName
     *
     * @return int|null
     */
    public function findStageIdByName(int $pipelineId, string $stageName): ?int
    {
        // MySQL's default collation is case-insensitive, so this matches a
        // stage named "discovery" too without lowering either side.
        $stageId = DealStage::query()
            ->where('pipeline_id', $pipelineId)
            ->where('name', $stageName)
            ->value('id');

        if ($stageId === null) {
            return null;
        }

        return (int) $stageId;
    }

    /**
     * The name of a stage, or null when no stage carries the id.
     *
     * @param int $dealStageId
     *
     * @return string|null
     */
    public function findStageNameById(int $dealStageId): ?string
    {
        $stageName = DealStage::query()->whereKey($dealStageId)->value('name');

        if ($stageName === null) {
            return null;
        }

        return (string) $stageName;
    }
}
