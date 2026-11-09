<?php

declare(strict_types=1);

namespace Core\Contact\Infrastructure\Repositories;

use App\Models\Activity;
use App\Models\Contact;
use Core\Contact\Application\Contracts\ContactQueryContract;
use Core\Contact\Domain\DataObjects\ContactDetailData;
use Core\Contact\Domain\ReadModels\ContactProjectionDTO;
use Core\Contact\Domain\ValueObjects\ContactFilter;
use Core\Contact\Infrastructure\Mappers\ContactEntityMapper;
use Core\Shared\Domain\DataObjects\PaginatedResult;

/**
 * Eloquent reads for the contact list and detail. Returns read models only.
 *
 * @implements ContactQueryContract
 */
final class ContactReadRepository implements ContactQueryContract
{
    /**
     * @param ContactEntityMapper $mapper
     */
    public function __construct(
        private readonly ContactEntityMapper $mapper,
    ) {}

    /**
     * Returns the contacts matching the given filter criteria.
     *
     * @param ContactFilter $filter
     *
     * @return PaginatedResult Items are ContactProjectionDTO instances.
     */
    public function getFilteredContacts(ContactFilter $filter): PaginatedResult
    {
        $query = Contact::query();

        if ($filter->search !== null) {
            // One term matched against the identifying columns, wrapped in a
            // closure so the ORs cannot escape a conjunction added later.
            $term = '%' . $filter->search . '%';

            $query->where(function ($query) use ($term) {
                $query->where('name', 'like', $term)
                      ->orWhere('email', 'like', $term)
                      ->orWhere('phone', 'like', $term);
            });
        }

        // The id tie-breaks rows sharing a created_at. Without it the order of
        // equal keys is undefined, so a paginated offset can repeat one row and
        // skip another. Two rows created in the same second are ordinary.
        $eloquentPaginator = $query
            ->with('companies')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($filter->perPage);

        // A list row shows values, so it leaves as a projection: hydrating a
        // ContactEntity per row would build behaviour the table never calls.
        $contacts = $eloquentPaginator
            ->getCollection()
            ->map(fn(Contact $item) => $this->toProjection($item))
            ->all();

        return new PaginatedResult(
            items: $contacts,
            total: $eloquentPaginator->total(),
            currentPage: $eloquentPaginator->currentPage(),
            perPage: $eloquentPaginator->perPage(),
            lastPage: $eloquentPaginator->lastPage(),
        );
    }

    /**
     * Returns one contact with its companies and activities.
     *
     * @param int $contactId
     *
     * @return ContactDetailData|null Null when no contact carries the id.
     */
    public function findDetailById(int $contactId): ?ContactDetailData
    {
        $contactModel = Contact::with(['companies', 'activities.user'])->find($contactId);

        if (! $contactModel instanceof Contact) {
            return null;
        }

        // The graph is flattened here, so nothing above Infrastructure knows how
        // Eloquent nests a relation.
        $activities = $contactModel->activities
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

        return new ContactDetailData(
            contact: $this->mapper->toEntity(
                data: $contactModel->toArray(),
                companyRows: $this->toCompanyRows($contactModel),
            ),
            activities: $activities,
        );
    }

    /**
     * Flattens the attached companies to plain rows, so the mapper need not know
     * how the pivot is read.
     *
     * @param Contact $contactModel
     *
     * @return array<int, array<string, mixed>>
     */
    private function toCompanyRows(Contact $contactModel): array
    {
        return array_values($contactModel->companies()
            ->get()
            ->map(fn($company) => [
                'id' => $company->id,
                'name' => $company->name,
                'phone' => $company->phone,
                'website' => $company->website,
                'industry' => $company->industry,
                'job_title' => $company->pivot?->job_title,
                'is_primary' => (bool) $company->pivot?->is_primary,
            ])
            ->all());
    }

    /**
     * Builds the list read model, carrying the attached companies as link
     * value objects so the list can show their names.
     *
     * @param Contact $contactModel
     *
     * @return ContactProjectionDTO
     */
    private function toProjection(Contact $contactModel): ContactProjectionDTO
    {
        return new ContactProjectionDTO(
            id: (int) $contactModel->id,
            name: (string) $contactModel->name,
            email: (string) $contactModel->email,
            phone: $contactModel->phone,
            createdAt: $contactModel->created_at?->toDateTimeString(),
            updatedAt: $contactModel->updated_at?->toDateTimeString(),
            companies: array_map(
                $this->mapper->toCompanyLink(...),
                $this->toCompanyRows($contactModel),
            ),
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
    private function toDateTimeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value->toDateTimeString();
    }
}
