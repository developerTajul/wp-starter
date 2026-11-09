<?php

declare(strict_types=1);

namespace Core\Deal\Infrastructure\Repositories;

use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Pipeline;
use Core\Deal\Application\Contracts\DealQueryContract;
use Core\Deal\Domain\ReadModels\DealProjectionDTO;
use Core\Deal\Domain\ReadModels\StoredDealData;
use Core\Deal\Domain\ValueObjects\DealFilter;
use Core\Deal\Infrastructure\Mappers\DealEntityMapper;
use Core\Shared\Domain\DataObjects\PaginatedResult;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent-backed reads for the deal list and detail. Returns read models only.
 *
 * @implements DealQueryContract
 */
final class DealReadRepository implements DealQueryContract
{
    /**
     * @param DealEntityMapper $mapper
     */
    public function __construct(
        private readonly DealEntityMapper $mapper,
    ) {}

    /**
     * Returns the deals matching the given filter criteria.
     *
     * Rows come back as projections: a list prints columns and related labels,
     * so it never needs a domain entity.
     *
     * @param DealFilter $filter
     *
     * @return PaginatedResult Items are DealProjectionDTO instances.
     */
    public function getFilteredDeals(DealFilter $filter): PaginatedResult
    {
        $query = Deal::query();

        if ($filter->pipelineId !== null) {
            $query->where('pipeline_id', $filter->pipelineId);
        }

        if ($filter->dealStageId !== null) {
            $query->where('deal_stage_id', $filter->dealStageId);
        }

        // The relations are narrowed to the columns the response prints, so a
        // list read never loads a whole record for a name.
        $eloquentPaginator = $query
            ->with(['company:id,name', 'contact:id,name', 'pipeline:id,name', 'stage:id,name,probability,order_position'])
            // The id tie-breaks rows sharing a created_at. Without it the order
            // of equal keys is undefined, so a paginated offset can repeat a row
            // on one page and skip another.
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($filter->perPage);

        $deals = $eloquentPaginator
            ->getCollection()
            ->map(fn(Deal $item) => $this->toProjection($item))
            ->all();

        return new PaginatedResult(
            items: $deals,
            total: $eloquentPaginator->total(),
            currentPage: $eloquentPaginator->currentPage(),
            perPage: $eloquentPaginator->perPage(),
            lastPage: $eloquentPaginator->lastPage(),
        );
    }

    /**
     * Returns one deal with the records it points at and its activities.
     *
     * Hydrates one entity, because the detail read carries the deal's own state;
     * a list read does not.
     *
     * @param int $id
     *
     * @return StoredDealData|null Null when no deal carries the id.
     */
    public function findDetailById(int $id): ?StoredDealData
    {
        $dealModel = Deal::with([
            'company:id,name',
            'contact:id,name',
            'pipeline:id,name,is_default',
            'stage:id,name,probability,order_position',
            'activities.user',
        ])->find($id);

        if (! $dealModel instanceof Deal) {
            return null;
        }

        // The entity is built from the deal's own attributes, before the loaded
        // relations are read, so its timestamps stay the deal's own.
        return new StoredDealData(
            deal: $this->mapper->toEntity($dealModel->getAttributes()),
            company: $this->toRelationRow($dealModel->company),
            contact: $this->toRelationRow($dealModel->contact),
            pipeline: $this->toRelationRow($dealModel->pipeline),
            stage: $this->toRelationRow($dealModel->stage),
            activities: $this->toActivityRows($dealModel),
        );
    }

    /**
     * Flattens a deal row and its related labels into a read model.
     *
     * @param Deal $dealModel
     *
     * @return DealProjectionDTO
     */
    private function toProjection(Deal $dealModel): DealProjectionDTO
    {
        return new DealProjectionDTO(
            id: (int) $dealModel->id,
            title: (string) $dealModel->title,
            companyId: $this->toNullableInt($dealModel->company_id),
            companyName: $this->toRelationName($dealModel->company),
            contactId: $this->toNullableInt($dealModel->contact_id),
            contactName: $this->toRelationName($dealModel->contact),
            amount: (string) $dealModel->amount,
            currency: (string) $dealModel->currency,
            pipelineId: (int) $dealModel->pipeline_id,
            pipelineName: $this->toRelationName($dealModel->pipeline),
            dealStageId: (int) $dealModel->deal_stage_id,
            stageName: $this->toRelationName($dealModel->stage),
            stageProbability: $this->toRelationAttribute($dealModel->stage, 'probability'),
            expectedCloseDate: $this->toNullableString($dealModel->expected_close_date),
            actualCloseDate: $this->toNullableString($dealModel->actual_close_date),
            createdAt: $this->toNullableString($dealModel->created_at),
            updatedAt: $this->toNullableString($dealModel->updated_at),
        );
    }

    /**
     * The name of a loaded relation row, or null when absent or not loaded.
     *
     * @param mixed $relation
     *
     * @return string|null
     */
    private function toRelationName(mixed $relation): ?string
    {
        if (! $relation instanceof Model) {
            return null;
        }

        $name = $relation->getAttribute('name');

        return $name === null ? null : (string) $name;
    }

    /**
     * One int attribute of a loaded relation row.
     *
     * @param mixed  $relation
     * @param string $attribute
     *
     * @return int|null
     */
    private function toRelationAttribute(mixed $relation, string $attribute): ?int
    {
        if (! $relation instanceof Model) {
            return null;
        }

        return $this->toNullableInt($relation->getAttribute($attribute));
    }

    /**
     * Flattens a related row to its columns, so nothing above Infrastructure
     * knows how Eloquent nests a relation.
     *
     * @param mixed $relation
     *
     * @return array<string, mixed>|null Null when absent or not loaded.
     */
    private function toRelationRow(mixed $relation): ?array
    {
        if ($relation instanceof Company || $relation instanceof Contact
            || $relation instanceof Pipeline || $relation instanceof DealStage) {
            return $relation->getAttributes();
        }

        return null;
    }

    /**
     * Flattens a deal's activities. The casts already make the timestamps date
     * objects, so only their string form is left to take.
     *
     * @param Deal $dealModel
     *
     * @return array<int, array<string, mixed>>
     */
    private function toActivityRows(Deal $dealModel): array
    {
        return $dealModel->activities
            ->map(fn(Activity $activity) => [
                'id' => $activity->id,
                'type' => $activity->type,
                'subject' => $activity->subject,
                'description' => $activity->description,
                'due_date' => $this->toDateTimeString($activity->due_date),
                'completed_at' => $this->toDateTimeString($activity->completed_at),
                'user_name' => $activity->user?->name,
            ])
            ->all();
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
     * The driver returns a nullable foreign key as null or a numeric string, so
     * both are narrowed to int.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    private function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * Narrows a stored value to string, reading an absent one as null.
     *
     * @param mixed $value
     *
     * @return string|null
     */
    private function toNullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
