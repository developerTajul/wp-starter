<?php

declare(strict_types=1);

namespace Core\Company\Infrastructure\Repositories;

use Core\Company\Application\Contracts\CompanyQueryContract;
use Core\Company\Domain\DataObjects\CompanyDetailData;
use Core\Company\Domain\Entities\CompanyEntity;
use Core\Company\Domain\ReadModels\CompanyActivityProjectionDTO;
use Core\Company\Domain\ReadModels\CompanyContactProjectionDTO;
use Core\Company\Domain\ReadModels\CompanyDealProjectionDTO;
use Core\Company\Domain\ReadModels\CompanyDealStageProjectionDTO;
use Core\Company\Domain\ReadModels\CompanyProjectionDTO;
use Core\Company\Domain\ValueObjects\CompanyFilter;
use Core\Company\Infrastructure\Mappers\CompanyEntityMapper;
use Core\Shared\Domain\DataObjects\PaginatedResult;
use Framework\Database\Query\QueryBuilderInterface;
use RuntimeException;

/**
 * Reads for the company list, detail and single lookup. Returns read models
 * only.
 *
 * @implements CompanyQueryContract
 */
final class WpCompanyReadRepository implements CompanyQueryContract
{
    /** The table's name without the WordPress prefix. */
    public const TABLE_SUFFIX = 'companies';

    /** Table suffix for the contact/company pivot. */
    private const COMPANY_CONTACT_TABLE = 'company_contact';

    /** Table suffix for contacts. */
    private const CONTACTS_TABLE = 'contacts';

    /** Table suffix for deals. */
    private const DEALS_TABLE = 'deals';

    /** Table suffix for deal stages. */
    private const DEAL_STAGES_TABLE = 'deal_stages';

    /** Table suffix for activities. */
    private const ACTIVITIES_TABLE = 'activities';

    /** Table suffix for the WordPress users table. */
    private const USERS_TABLE = 'users';

    /** Morph alias a company's own activities are stored under. */
    private const COMPANY_TRACKABLE_TYPE = 'company';

    /** Morph alias a deal's activities are stored under. */
    private const DEAL_TRACKABLE_TYPE = 'deal';

    /**
     * The columns the reads select, spelled out rather than `*`.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id', 'name', 'website', 'industry', 'phone', 'address', 'created_at', 'updated_at',
    ];

    /**
     * The order the list is always read in.
     *
     * A constant rather than a request value: the list offers no sorting
     * control, so there is no untrusted text to whitelist and no direction to
     * normalise. Both are settled here in code, which is also what keeps the
     * `ORDER BY` clause free of anything a URL could reach.
     */
    private const ORDER_COLUMN = 'created_at';

    /** The direction {@see self::ORDER_COLUMN} is read in. */
    private const ORDER_DIRECTION = 'desc';

    /**
     * @param QueryBuilderInterface $query  The fluent builder, bound to the
     *                                      WordPress connection by its provider.
     * @param CompanyEntityMapper   $mapper
     */
    public function __construct(
        private readonly QueryBuilderInterface $query,
        private readonly CompanyEntityMapper $mapper,
    ) {
    }

    /**
     * Returns one page of companies in the list's own order.
     *
     * The count and the slice read the same table, so the total and the rows
     * cannot describe different sets - the failure that shows up as a page of
     * rows under a count that does not admit them.
     *
     * @param CompanyFilter $filter
     *
     * @return PaginatedResult Items are CompanyProjectionDTO instances.
     */
    public function getFilteredCompanies(CompanyFilter $filter): PaginatedResult
    {
        $perPage = $filter->perPage;
        $page = $filter->page;

        $total = $this->query->table(self::TABLE_SUFFIX)->count();

        // The count settles the real bound, so a page past the end is clamped
        // to the last one before the offset is computed - otherwise the read
        // selects past the end and the pager shows a blank screen under a
        // count that does not admit the page. PaginatedResult owns that
        // arithmetic, so the clamp asks it rather than re-deriving the bounds.
        $requested = PaginatedResult::fromSlice(
            items: [],
            total: $total,
            perPage: $perPage,
            currentPage: $page
        );

        $page = $requested->isBeyondLastPage() ? $requested->lastPage() : $requested->currentPage();

        $rows = $this->sortedQuery()
            ->select(...self::COLUMNS)
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        return PaginatedResult::fromSlice(
            items: array_map(fn (array $row): CompanyProjectionDTO => $this->toProjection($row), $rows),
            total: $total,
            perPage: $perPage,
            currentPage: $page
        );
    }

    /**
     * A builder reading the company table in the list's own order.
     *
     * @return QueryBuilderInterface
     */
    private function sortedQuery(): QueryBuilderInterface
    {
        return $this->query->table(self::TABLE_SUFFIX)
            ->orderBy(self::ORDER_COLUMN, self::ORDER_DIRECTION)
            ->orderByDesc('id');
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
        $row = $this->query
            ->table(self::TABLE_SUFFIX)
            ->select(...self::COLUMNS)
            ->where('id', $companyId)
            ->first();

        return $row === null ? null : $this->mapper->toEntity($row);
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
        $company = $this->findById($companyId);

        if ($company === null) {
            return null;
        }

        return new CompanyDetailData(
            id: $company->id ?? $companyId,
            name: $company->name,
            website: $company->website,
            industry: $company->industry,
            phone: $company->phone,
            address: $company->address,
            createdAt: $company->createdAt,
            updatedAt: $company->updatedAt,
            contacts: $this->contactsFor($companyId),
            deals: $this->dealsFor($companyId),
            activities: $this->activitiesFor($companyId),
        );
    }

    // ------------------------------------------------------------------ joins

    /**
     * The contacts linked to a company, carrying the pivot's own fields.
     *
     * @param int $companyId
     *
     * @return list<CompanyContactProjectionDTO>
     */
    private function contactsFor(int $companyId): array
    {
        $rows = $this->query
            ->table(self::COMPANY_CONTACT_TABLE)
            ->select('company_contact.id', 'name', 'email', 'phone', 'job_title', 'is_primary')
            ->join(self::CONTACTS_TABLE, 'contacts.id', '=', 'company_contact.contact_id')
            ->where('company_contact.company_id', $companyId)
            ->orderByDesc('company_contact.is_primary')
            ->orderBy('contacts.name')
            ->get();

        return array_map(
            fn (array $row): CompanyContactProjectionDTO => new CompanyContactProjectionDTO(
                id: (int) $row['id'],
                name: (string) $row['name'],
                email: $this->toNullableString($row['email'] ?? null),
                phone: $this->toNullableString($row['phone'] ?? null),
                jobTitle: $this->toNullableString($row['job_title'] ?? null),
                // MySQL returns a TINYINT as a string; `(bool) '0'` is false,
                // which is what the column means. A missing flag also reads as
                // false, which is the column's own default.
                isPrimary: (bool) ($row['is_primary'] ?? false),
            ),
            $rows
        );
    }

    /**
     * The deals opened against a company, each with its stage and activities.
     *
     * @param int $companyId
     *
     * @return list<CompanyDealProjectionDTO>
     */
    private function dealsFor(int $companyId): array
    {
        $rows = $this->query
            ->table(self::DEALS_TABLE)
            ->select(
                'deals.id',
                'title',
                'amount',
                'currency',
                'expected_close_date',
                'actual_close_date',
                'deal_stages.id AS stage_id',
                'deal_stages.name AS stage_name',
                'deal_stages.order_position AS stage_order_position',
                'deal_stages.probability AS stage_probability',
            )
            ->leftJoin(self::DEAL_STAGES_TABLE, 'deal_stages.id', '=', 'deals.deal_stage_id')
            ->where('deals.company_id', $companyId)
            ->orderByDesc('deals.created_at')
            ->orderByDesc('deals.id')
            ->get();

        // The deals' activities are read once for the company rather than once
        // per deal: a single query beats N queries, and the grouping happens in
        // PHP where it is visible.
        $activitiesByDeal = $this->activitiesGroupedByDeal($companyId);

        return array_map(
            fn (array $row): CompanyDealProjectionDTO => new CompanyDealProjectionDTO(
                id: (int) $row['id'],
                title: (string) $row['title'],
                // P2: decimal money is a string. Casting to float would lose the
                // exactness the column was widened to `DECIMAL(15,2)` for.
                amount: (string) $row['amount'],
                currency: (string) $row['currency'],
                expectedCloseDate: $this->toNullableString($row['expected_close_date'] ?? null),
                actualCloseDate: $this->toNullableString($row['actual_close_date'] ?? null),
                stage: $this->toStageProjection($row),
                activities: $activitiesByDeal[(int) $row['id']] ?? [],
            ),
            $rows
        );
    }

    /**
     * The company's own activities, which are stored against the company rather
     * than against one of its deals.
     *
     * @param int $companyId
     *
     * @return list<CompanyActivityProjectionDTO>
     */
    private function activitiesFor(int $companyId): array
    {
        $rows = $this->activityRows()
            ->where('activities.trackable_type', self::COMPANY_TRACKABLE_TYPE)
            ->where('activities.trackable_id', $companyId)
            ->get();

        return array_values(array_map(
            fn (array $row): CompanyActivityProjectionDTO => $this->toActivityProjection($row),
            $rows
        ));
    }

    /**
     * The company's deal activities, grouped by deal.
     *
     * @param int $companyId
     *
     * @return array<int, list<CompanyActivityProjectionDTO>>
     */
    private function activitiesGroupedByDeal(int $companyId): array
    {
        $dealIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->query
                ->table(self::DEALS_TABLE)
                ->select('id')
                ->where('company_id', $companyId)
                ->get()
        );

        if ($dealIds === []) {
            return [];
        }

        $rows = $this->activityRows()
            ->where('activities.trackable_type', self::DEAL_TRACKABLE_TYPE)
            ->whereIn('activities.trackable_id', $dealIds)
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $trackableId = (int) $row['trackable_id'];

            $grouped[$trackableId] ??= [];
            $grouped[$trackableId][] = $this->toActivityProjection($row);
        }

        return $grouped;
    }

    /**
     * A builder for activity rows joined to their author.
     *
     * @return QueryBuilderInterface
     */
    private function activityRows(): QueryBuilderInterface
    {
        return $this->query
            ->table(self::ACTIVITIES_TABLE)
            ->select(
                'activities.id',
                'type',
                'subject',
                'description',
                'due_date',
                'completed_at',
                'trackable_id',
                'users.display_name AS user_name',
            )
            ->leftJoin(self::USERS_TABLE, 'users.ID', '=', 'activities.user_id')
            ->orderByDesc('activities.created_at')
            ->orderByDesc('activities.id');
    }

    // ------------------------------------------------------------------ mapping

    /**
     * Flattens a row onto the columns the list shows.
     *
     * @param array<string, mixed> $row
     *
     * @return CompanyProjectionDTO
     */
    private function toProjection(array $row): CompanyProjectionDTO
    {
        return new CompanyProjectionDTO(
            id: (int) $row['id'],
            name: (string) $row['name'],
            website: $this->toNullableString($row['website'] ?? null),
            industry: $this->toNullableString($row['industry'] ?? null),
            phone: $this->toNullableString($row['phone'] ?? null),
            address: $this->toNullableString($row['address'] ?? null),
            createdAt: $this->toNullableString($row['created_at'] ?? null),
            updatedAt: $this->toNullableString($row['updated_at'] ?? null),
        );
    }

    /**
     * Builds the stage read model from a joined deal row, or null when the
     * joined stage is absent.
     *
     * @param array<string, mixed> $row
     *
     * @return CompanyDealStageProjectionDTO|null
     */
    private function toStageProjection(array $row): ?CompanyDealStageProjectionDTO
    {
        // A LEFT JOIN yields NULL in every stage column when there is no stage.
        // `id` is the one never null on a real row, so its absence decides.
        if (($row['stage_id'] ?? null) === null) {
            return null;
        }

        return new CompanyDealStageProjectionDTO(
            id: (int) $row['stage_id'],
            name: (string) $row['stage_name'],
            orderPosition: (int) $row['stage_order_position'],
            probability: (int) $row['stage_probability'],
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return CompanyActivityProjectionDTO
     */
    private function toActivityProjection(array $row): CompanyActivityProjectionDTO
    {
        return new CompanyActivityProjectionDTO(
            id: (int) $row['id'],
            type: (string) $row['type'],
            subject: (string) $row['subject'],
            description: $this->toNullableString($row['description'] ?? null),
            dueDate: $this->toNullableString($row['due_date'] ?? null),
            completedAt: $this->toNullableString($row['completed_at'] ?? null),
            // The LEFT JOIN yields NULL for a deleted author; the alias is
            // absent from the row only if the driver dropped it, so the null
            // coalesce covers that rather than reading an undefined key.
            userName: $this->toNullableString($row['user_name'] ?? null),
        );
    }

    // ------------------------------------------------------------------ internals

    /**
     * A column value that is absent when null or empty.
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
