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
use wpdb;

/**
 * Reads for the company list, detail and single lookup. Returns read models
 * only.
 *
 * Every read runs on the fluent builder, including the paged list and the
 * joined detail reads. Nothing here issues raw SQL or touches `$wpdb`.
 *
 * @implements CompanyQueryContract
 */
final class WPFluentCompanyReadRepository implements CompanyQueryContract
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

        $total = $this->query->table(self::TABLE_SUFFIX)->count();

        // The count settles the real bound, so the requested page is clamped to
        // it BEFORE the offset is computed. A bookmarked `?paged=3` against a
        // list that has since shrunk to one page would otherwise select an offset
        // past the end: the table comes back empty while the pager still reads
        // "3 of 1", leaving the user on a blank screen whose only way out is
        // editing the URL by hand.
        //
        // The bounds come from PaginatedResult rather than from a local
        // `ceil($total / $perPage)`: it owns that arithmetic and `isBeyondLastPage()`
        // IS the "you are nowhere" signal, so re-deriving it here would be a
        // second copy of both to keep in step.
        $requested = PaginatedResult::fromSlice(
            items: [],
            total: $total,
            perPage: $perPage,
            currentPage: $this->currentPage()
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
     * The order is fixed ({@see self::ORDER_COLUMN} descending). It is applied
     * here rather than passed in because the screen has no sorting control, so
     * no request value takes part in it - there is nothing to whitelist and no
     * direction to normalise, and the `ORDER BY` clause cannot be reached from a
     * URL at all.
     *
     * The `id` tie-breaker is always `DESC` and always last: it exists to make
     * the order of equal sort keys stable so a paginated offset cannot repeat a
     * row on one page and skip another.
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
     * Read as separate statements rather than one joined statement: the three
     * collections are independent of each other, and joining them together
     * would multiply the deal rows by the contact rows and hand back a
     * cartesian product to de-duplicate in PHP.
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
     * The pivot is the FROM table and `contacts` is joined to it, because the
     * predicate narrows on the pivot's `company_id`; joining the other way round
     * would read every contact and discard almost all of them.
     *
     * @param int $companyId
     *
     * @return list<CompanyContactProjectionDTO>
     */
    private function contactsFor(int $companyId): array
    {
        $rows = $this->query
            ->table(self::COMPANY_CONTACT_TABLE)
            ->select('id', 'name', 'email', 'phone', 'job_title', 'is_primary')
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
     * The stage is a `LEFT JOIN` rather than an inner one: an inner join would
     * drop a deal whose stage row had gone, and a missing deal is a worse
     * failure than a deal shown without its stage. The stage columns come back
     * null in that case, which {@see toStageProjection()} turns into a null
     * stage rather than a half-built one.
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
                'id',
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
     * The company's deal activities, grouped by the deal each one tracks.
     *
     * The deal ids are read first and passed to `whereIn()`, rather than using a
     * correlated sub-select: the builder has no sub-query support, and a list of
     * ids is a predicate it can express - and bind - directly.
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

        // No deals means no deal activities, and `whereIn()` refuses an empty
        // list - so the read is skipped rather than asked a question with no
        // meaningful answer.
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
     * The user join is a `LEFT JOIN` because `activities.user_id` is nullable and
     * is set to NULL when the user is deleted, so an inner join would silently
     * drop activities whose author is gone. WordPress owns that table and its
     * display name is `display_name`, not the `name` an Eloquent model exposed.
     *
     * The projection is qualified with the `a` alias the join introduces: `id`
     * alone is ambiguous to MySQL once two tables are in play, and is rejected
     * rather than resolved to one of them.
     *
     * @return QueryBuilderInterface
     */
    private function activityRows(): QueryBuilderInterface
    {
        return $this->query
            ->table(self::ACTIVITIES_TABLE)
            ->select(
                'id',
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
     * The page being requested, from the query string.
     *
     * Read from `paged`, WordPress's own key for a list table's page, and not
     * from `page`: on a wp-admin screen `page` carries the menu slug WordPress
     * uses to choose which screen renders, so `admin.php?page=companies` would
     * be read as the string `"companies"` and cast to `0` on every request -
     * pinning the list to page 1 and making the pager unable to move.
     *
     * `CompanyFilter` carries no page, and reading it here keeps that value
     * object free of transport state. `$_GET` rather than the framework Request
     * because this class sits in `core/` and cannot name `Framework\Http\Request`.
     *
     * A missing or unparseable value is page 1, not an error: a bad page in a
     * URL is someone typing, not a fault.
     *
     * Only the floor is settled here. The ceiling cannot be: it depends on how
     * many rows match, which takes the count {@see getFilteredCompanies()} makes.
     * That method clamps the value this returns.
     *
     * @return int
     */
    private function currentPage(): int
    {
        $page = $_GET['paged'] ?? null;

        if (! is_scalar($page)) {
            return 1;
        }

        return max(1, (int) $page);
    }

    /**
     * A value that is absent when the column is null or an empty string.
     *
     * An empty string is absent, not a value: some drivers deliver a nullable
     * column that way, and a stored `''` reads as "nothing here" for every field
     * on this screen.
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
