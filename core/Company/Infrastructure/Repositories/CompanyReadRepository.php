<?php

declare(strict_types=1);

namespace Core\Company\Infrastructure\Repositories;

use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use Core\Company\Application\Contracts\CompanyQueryContract;
use Core\Company\Domain\DataObjects\CompanyDetailData;
use Core\Company\Domain\Entities\CompanyEntity;
use Core\Company\Domain\ReadModels\CompanyActivityProjectionDTO;
use Core\Company\Domain\ReadModels\CompanyContactProjectionDTO;
use Core\Company\Domain\ReadModels\CompanyDealProjectionDTO;
use Core\Company\Domain\ReadModels\CompanyDealStageProjectionDTO;
use Core\Company\Domain\ReadModels\CompanyProjectionDTO;
use Core\Company\Domain\ValueObjects\CompanyFilter;
use Core\Shared\Domain\DataObjects\PaginatedResult;

/**
 * Eloquent-backed reads for the company list, detail and single lookup.
 * Returns read models only.
 *
 * @implements CompanyQueryContract
 */
final class CompanyReadRepository implements CompanyQueryContract
{
    /**
     * Returns the companies matching the given filter criteria.
     *
     * @param CompanyFilter $filter
     *
     * @return PaginatedResult Items are CompanyProjectionDTO instances.
     */
    public function getFilteredCompanies(CompanyFilter $filter): PaginatedResult
    {
        $query = Company::query();

        if ($filter->search !== null) {
            // One term matched against the identifying columns. Wrapped in a
            // closure so the ORs cannot escape a conjunction added later.
            $term = '%' . $filter->search . '%';

            $query->where(function ($query) use ($term) {
                $query->where(column: 'name', operator: 'like', value: $term)
                      ->orWhere(column: 'industry', operator: 'like', value: $term);
            });
        }

        // The id tie-breaks rows sharing a created_at. Without it the order of
        // equal keys is undefined, so a paginated offset can repeat a row on one
        // page and skip another.
        $eloquentPaginator = $query
            ->orderBy(column: 'created_at', direction: 'desc')
            ->orderBy(column: 'id', direction: 'desc')
            ->paginate($filter->perPage);

        // A list row shows values, so it leaves as a projection: hydrating a
        // CompanyEntity per row would build behaviour nothing calls.
        $companies = $eloquentPaginator
            ->getCollection()
            ->map(fn(Company $company) => $this->toProjection($company))
            ->all();

        return new PaginatedResult(
            items: $companies,
            total: $eloquentPaginator->total(),
            currentPage: $eloquentPaginator->currentPage(),
            perPage: $eloquentPaginator->perPage(),
            lastPage: $eloquentPaginator->lastPage(),
        );
    }

    /**
     * Returns one company on its own, without the related records the detail
     * read drags in.
     *
     * @param int $companyId
     *
     * @return CompanyEntity|null Null when no company carries the id.
     */
    public function findById(int $companyId): ?CompanyEntity
    {
        $companyModel = Company::find($companyId);

        if (! $companyModel instanceof Company) {
            return null;
        }

        return CompanyEntity::reconstitute(
            id: (int) $companyModel->id,
            name: (string) $companyModel->name,
            website: $companyModel->website,
            industry: $companyModel->industry,
            phone: $companyModel->phone,
            address: $companyModel->address,
            createdAt: $companyModel->created_at?->toDateTimeString(),
            updatedAt: $companyModel->updated_at?->toDateTimeString(),
        );
    }

    /**
     * Returns one company with its contacts, deals, deal stages and deal
     * activities.
     *
     * @param int $companyId
     *
     * @return CompanyDetailData|null Null when no company carries the id.
     */
    public function findDetailById(int $companyId): ?CompanyDetailData
    {
        $companyModel = Company::with([
            'contacts',
            'deals.stage',
            'deals.activities.user',
        ])->find($companyId);

        if (! $companyModel instanceof Company) {
            return null;
        }

        // The graph is flattened here, so nothing above Infrastructure has to
        // know how Eloquent nests a relation. Each row leaves as a typed read
        // model, so a renamed column fails here rather than in a mapper.
        $deals = $companyModel->deals
            ->map(fn(Deal $deal) => new CompanyDealProjectionDTO(
                id: (int) $deal->id,
                title: (string) $deal->title,
                amount: (string) $deal->amount,
                currency: (string) $deal->currency,
                expectedCloseDate: $this->toDateString($deal->expected_close_date),
                actualCloseDate: $this->toDateString($deal->actual_close_date),
                stage: $this->toStageProjection($deal->stage),
                activities: $deal->activities
                    ->map(fn(Activity $activity) => $this->toActivityProjection($activity))
                    ->all(),
            ))
            ->all();

        $contacts = $companyModel->contacts
            ->map(fn(Contact $contact) => new CompanyContactProjectionDTO(
                id: (int) $contact->id,
                name: (string) $contact->name,
                email: $contact->email,
                phone: $contact->phone,
                jobTitle: $contact->pivot?->job_title,
                isPrimary: (bool) $contact->pivot?->is_primary,
            ))
            ->all();

        $activities = $companyModel->activities
            ->map(fn(Activity $activity) => $this->toActivityProjection($activity))
            ->all();

        return new CompanyDetailData(
            id: (int) $companyModel->id,
            name: (string) $companyModel->name,
            website: $companyModel->website,
            industry: $companyModel->industry,
            phone: $companyModel->phone,
            address: $companyModel->address,
            createdAt: $companyModel->created_at?->toDateTimeString(),
            updatedAt: $companyModel->updated_at?->toDateTimeString(),
            contacts: $contacts,
            deals: $deals,
            activities: $activities,
        );
    }

    /**
     * Flattens a model onto the columns the list shows.
     *
     * @param Company $company
     *
     * @return CompanyProjectionDTO
     */
    private function toProjection(Company $company): CompanyProjectionDTO
    {
        return new CompanyProjectionDTO(
            id: (int) $company->id,
            name: (string) $company->name,
            website: $company->website,
            industry: $company->industry,
            phone: $company->phone,
            address: $company->address,
            createdAt: $company->created_at?->toDateTimeString(),
            updatedAt: $company->updated_at?->toDateTimeString(),
        );
    }

    /**
     * Builds the stage read model, or null when the deal carries no stage.
     *
     * @param mixed $stage
     *
     * @return CompanyDealStageProjectionDTO|null
     */
    private function toStageProjection(mixed $stage): ?CompanyDealStageProjectionDTO
    {
        if ($stage === null) {
            return null;
        }

        return new CompanyDealStageProjectionDTO(
            id: (int) $stage->id,
            name: (string) $stage->name,
            orderPosition: (int) $stage->order_position,
            probability: (int) $stage->probability,
        );
    }

    /**
     * @param Activity $activity
     *
     * @return CompanyActivityProjectionDTO
     */
    private function toActivityProjection(Activity $activity): CompanyActivityProjectionDTO
    {
        return new CompanyActivityProjectionDTO(
            id: (int) $activity->id,
            type: (string) $activity->type,
            subject: (string) $activity->subject,
            description: $activity->description,
            dueDate: $this->toDateTimeString($activity->due_date),
            completedAt: $this->toDateTimeString($activity->completed_at),
            userName: $activity->user?->name,
        );
    }

    /**
     * The casts already turn these into date objects, so only the string form
     * is left to take.
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
}
