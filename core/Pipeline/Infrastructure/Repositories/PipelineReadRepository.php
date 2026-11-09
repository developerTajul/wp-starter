<?php

declare(strict_types=1);

namespace Core\Pipeline\Infrastructure\Repositories;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Pipeline;
use Core\Pipeline\Application\Contracts\PipelineQueryContract;
use Core\Pipeline\Domain\DataObjects\KanbanBoardData;
use Core\Pipeline\Domain\DataObjects\KanbanDealData;
use Core\Pipeline\Domain\DataObjects\KanbanStageData;
use Core\Pipeline\Domain\DataObjects\PipelineDetailData;
use Core\Pipeline\Domain\ReadModels\PipelineProjectionDTO;
use Core\Pipeline\Infrastructure\Mappers\PipelineEntityMapper;

/**
 * Eloquent-backed reads for pipelines, their stages and the Kanban board.
 * Returns read models only.
 *
 * @implements PipelineQueryContract
 */
final class PipelineReadRepository implements PipelineQueryContract
{
    /**
     * @param PipelineEntityMapper $mapper
     */
    public function __construct(
        private readonly PipelineEntityMapper $mapper,
    ) {}

    /**
     * Returns one pipeline with its stages, ordered by position.
     *
     * @param int $id
     *
     * @return PipelineDetailData|null Null when no pipeline carries the id.
     */
    public function findDetailById(int $id): ?PipelineDetailData
    {
        $pipelineModel = Pipeline::query()->find($id);

        if (! $pipelineModel instanceof Pipeline) {
            return null;
        }

        return $this->toDetailData($pipelineModel);
    }

    /**
     * Returns every pipeline with its stages.
     *
     * @return array<int, PipelineDetailData> Empty when no pipeline exists.
     */
    public function getAllWithStages(): array
    {
        return Pipeline::query()
            ->orderBy('id')
            ->get()
            ->map(fn(Pipeline $pipelineModel) => $this->toDetailData($pipelineModel))
            ->all();
    }

    /**
     * Returns one pipeline with its stages and each column's deals, as the
     * Kanban board shows them. Stages in working order, deals newest first, and
     * company/contact narrowed to their name columns.
     *
     * @param int $id
     *
     * @return KanbanBoardData|null Null when no pipeline carries the id.
     */
    public function findKanbanBoardById(int $id): ?KanbanBoardData
    {
        $pipelineModel = Pipeline::query()
            ->with([
                'stages' => fn($query) => $query
                    ->orderBy('order_position')
                    ->with([
                        'deals' => fn($dealQuery) => $dealQuery
                            ->with(['company:id,name', 'contact:id,name'])
                            ->latest(),
                    ]),
            ])
            ->find($id);

        if (! $pipelineModel instanceof Pipeline) {
            return null;
        }

        return new KanbanBoardData(
            pipeline: $this->toProjection($pipelineModel->toArray()),
            stages: $pipelineModel->stages
                ->map(fn(DealStage $stage) => $this->toKanbanStageData($stage))
                ->all(),
        );
    }

    /**
     * Flattens one stage and the deals sitting in it.
     *
     * @param DealStage $stageModel
     *
     * @return KanbanStageData
     */
    private function toKanbanStageData(DealStage $stageModel): KanbanStageData
    {
        return new KanbanStageData(
            id: $stageModel->id,
            name: $stageModel->name,
            orderPosition: $stageModel->order_position,
            probability: $stageModel->probability,
            deals: $stageModel->deals
                ->map(fn(Deal $deal) => $this->toKanbanDealData($deal))
                ->all(),
        );
    }

    /**
     * Flattens one deal. The amount stays a decimal string, so the column's
     * scale survives the trip to the client.
     *
     * @param Deal $dealModel
     *
     * @return KanbanDealData
     */
    private function toKanbanDealData(Deal $dealModel): KanbanDealData
    {
        return new KanbanDealData(
            id: $dealModel->id,
            title: $dealModel->title,
            companyId: $dealModel->company_id,
            contactId: $dealModel->contact_id,
            amount: (string) $dealModel->amount,
            currency: $dealModel->currency,
            pipelineId: $dealModel->pipeline_id,
            dealStageId: $dealModel->deal_stage_id,
            expectedCloseDate: $this->toDateString($dealModel->expected_close_date),
            actualCloseDate: $this->toDateString($dealModel->actual_close_date),
            createdAt: $this->toDateTimeString($dealModel->created_at),
            updatedAt: $this->toDateTimeString($dealModel->updated_at),
            companyName: $dealModel->company?->name,
            contactName: $dealModel->contact?->name,
        );
    }

    /**
     * The casts already make this a date object, so only its string form is left.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private function toDateString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value->toDateString();
    }

    /**
     * @param mixed $value
     *
     * @return string|null
     */
    private function toDateTimeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value->toDateTimeString();
    }

    /**
     * Flattens a pipeline and its stages into the read model both detail reads
     * return. No Entity is hydrated: only the columns are read.
     *
     * @param Pipeline $pipelineModel
     *
     * @return PipelineDetailData
     */
    private function toDetailData(Pipeline $pipelineModel): PipelineDetailData
    {
        return new PipelineDetailData(
            pipeline: $this->toProjection($pipelineModel->toArray()),
            stages: $pipelineModel->stages()
                ->orderBy('order_position')
                ->get()
                ->map(fn(DealStage $stage) => $stage->toArray())
                ->all(),
        );
    }

    /**
     * Narrows a pipeline model array to the read model the screens show.
     *
     * @param array<string, mixed> $data
     *
     * @return PipelineProjectionDTO
     */
    private function toProjection(array $data): PipelineProjectionDTO
    {
        return new PipelineProjectionDTO(
            id: (int) $data['id'],
            name: (string) $data['name'],
            isDefault: (bool) $data['is_default'],
            createdAt: $data['created_at'] ?? null,
            updatedAt: $data['updated_at'] ?? null,
        );
    }
}
