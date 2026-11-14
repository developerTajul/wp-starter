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
use RuntimeException;
use wpdb;

/**
 * `$wpdb` reads for the company list, detail and single lookup. Returns read
 * models only.
 *
 * Every statement binds its values through `$wpdb->prepare()`. The two things
 * that cannot be bound — a column list and the `ORDER BY` — are settled in code
 * from class constants, never from a request, so no value reaches a statement as
 * text.
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

    private const OUTPUT_ASSOC = ARRAY_A;

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
     * A constant rather than a request value: the list offers no sorting control,
     * so there is no untrusted text to whitelist and no direction to normalise.
     * Both are settled here in code, which is also what keeps the `ORDER BY`
     * clause free of anything a URL could reach.
     */
    private const ORDER_COLUMN = 'created_at';

    /** The direction {@see self::ORDER_COLUMN} is read in. */
    private const ORDER_DIRECTION = 'DESC';

    /**
     * @param CompanyEntityMapper $mapper
     * @param wpdb|null $wpdb Defaults to the `$wpdb` global, resolved lazily; see
     *                        {@see db()}.
     */
    public function __construct(
        private readonly CompanyEntityMapper $mapper,
        private ?wpdb $wpdb = null,
    ) {
    }

    /**
     * Returns one page of companies in the list's own order.
     *
     * The count and the slice read the same table, so the total and the rows
     * cannot describe different sets — the failure that shows up as a page of
     * rows under a count that does not admit them.
     *
     * @param CompanyFilter $filter
     *
     * @return PaginatedResult Items are CompanyProjectionDTO instances.
     */
    public function getFilteredCompanies(CompanyFilter $filter): PaginatedResult
    {
        $db = $this->db();
        $table = $this->escapeIdentifier($this->tableName(self::TABLE_SUFFIX));

        $perPage = $filter->perPage;
        $page = $filter->page;

        $total = (int) $db->get_var(sprintf('SELECT COUNT(*) FROM `%s`', $table));

        // The count settles the real bound, so a page past the end is clamped to
        // the last one before the offset is computed — otherwise the read selects
        // past the end and the pager shows a blank screen under a count that does
        // not admit the page. PaginatedResult owns that arithmetic, so the clamp
        // asks it rather than re-deriving the bounds.
        $requested = PaginatedResult::fromSlice(
            items: [],
            total: $total,
            perPage: $perPage,
            currentPage: $page
        );

        $page = $requested->isBeyondLastPage() ? $requested->lastPage() : $requested->currentPage();
        $offset = ($page - 1) * $perPage;

        // LIMIT and OFFSET are ints this class computed from a validated page
        // size. `%d` is left unquoted by `prepare()`, so they reach the clause as
        // bare integers, which is what MySQL's `LIMIT` requires.
        $rows = $this->rows(
            $db->prepare(
                sprintf(
                    'SELECT `%s` FROM `%s` ORDER BY `%s` %s, `id` DESC LIMIT %%d OFFSET %%d',
                    implode('`, `', self::COLUMNS),
                    $table,
                    self::ORDER_COLUMN,
                    self::ORDER_DIRECTION
                ),
                $perPage,
                $offset
            ),
            'read the company list'
        );

        return PaginatedResult::fromSlice(
            items: array_map(fn (array $row): CompanyProjectionDTO => $this->toProjection($row), $rows),
            total: $total,
            perPage: $perPage,
            currentPage: $page
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
        $row = $this->findRow(
            sprintf(
                'SELECT `%s` FROM `%s` WHERE `id` = %%d LIMIT 1',
                implode('`, `', self::COLUMNS),
                $this->escapeIdentifier($this->tableName(self::TABLE_SUFFIX))
            ),
            [$companyId],
            sprintf('read company %d', $companyId)
        );

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
        $pivot = $this->column(self::COMPANY_CONTACT_TABLE);
        $contacts = $this->column(self::CONTACTS_TABLE);

        $rows = $this->rows(
            $this->prepare(
                sprintf(
                    'SELECT %s, %s, %s, %s, %s, %s '
                    . 'FROM %s INNER JOIN %s ON %s = %s '
                    . 'WHERE %s = %%d '
                    . 'ORDER BY %s DESC, %s ASC',
                    "{$pivot}.`id`",
                    "{$contacts}.`name`",
                    "{$contacts}.`email`",
                    "{$contacts}.`phone`",
                    // `job_title` and `is_primary` belong to the pivot, not the
                    // contact: the same person can hold a different title at each
                    // company. `id` is the pivot's row id for that reason.
                    "{$pivot}.`job_title`",
                    "{$pivot}.`is_primary`",
                    $this->tableName(self::COMPANY_CONTACT_TABLE),
                    $this->tableName(self::CONTACTS_TABLE),
                    "{$contacts}.`id`",
                    "{$pivot}.`contact_id`",
                    "{$pivot}.`company_id`",
                    "{$pivot}.`is_primary`",
                    "{$contacts}.`name`"
                ),
                [$companyId]
            ),
            'read the contacts of company ' . $companyId
        );

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
        $deals = $this->column(self::DEALS_TABLE);
        $stages = $this->column(self::DEAL_STAGES_TABLE);

        $rows = $this->rows(
            $this->prepare(
                sprintf(
                    'SELECT %s, %s, %s, %s, %s, %s, '
                    . '%s AS `stage_id`, %s AS `stage_name`, '
                    . '%s AS `stage_order_position`, %s AS `stage_probability` '
                    . 'FROM %s LEFT JOIN %s ON %s = %s '
                    . 'WHERE %s = %%d '
                    . 'ORDER BY %s DESC, %s DESC',
                    "{$deals}.`id`",
                    "{$deals}.`title`",
                    "{$deals}.`amount`",
                    "{$deals}.`currency`",
                    "{$deals}.`expected_close_date`",
                    "{$deals}.`actual_close_date`",
                    "{$stages}.`id`",
                    "{$stages}.`name`",
                    "{$stages}.`order_position`",
                    "{$stages}.`probability`",
                    $this->tableName(self::DEALS_TABLE),
                    $this->tableName(self::DEAL_STAGES_TABLE),
                    "{$stages}.`id`",
                    "{$deals}.`deal_stage_id`",
                    "{$deals}.`company_id`",
                    "{$deals}.`created_at`",
                    "{$deals}.`id`"
                ),
                [$companyId]
            ),
            'read the deals of company ' . $companyId
        );

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
        $activities = $this->column(self::ACTIVITIES_TABLE);

        $rows = $this->rows(
            $this->activityQuery(
                sprintf(
                    'WHERE %s = %%s AND %s = %%d',
                    "{$activities}.`trackable_type`",
                    "{$activities}.`trackable_id`"
                ),
                [self::COMPANY_TRACKABLE_TYPE, $companyId]
            ),
            'read the activities of company ' . $companyId
        );

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
        $db = $this->db();

        $dealRows = $this->rows(
            $this->prepare(
                sprintf(
                    'SELECT `id` FROM `%s` WHERE `company_id` = %%d',
                    $this->escapeIdentifier($this->tableName(self::DEALS_TABLE))
                ),
                [$companyId]
            ),
            'read the deal ids of company ' . $companyId
        );

        $dealIds = array_map(static fn (array $row): int => (int) $row['id'], $dealRows);

        if ($dealIds === []) {
            return [];
        }

        // `whereIn` has no bound-parameter form, so the placeholders are
        // generated one per id and the ids are bound through `prepare()`. The
        // ids are ints cast from a column, never text from a request.
        $placeholders = implode(', ', array_fill(0, count($dealIds), '%d'));

        $rows = $this->rows(
            $this->activityQuery(
                sprintf(
                    'WHERE %s = %%s AND %s IN (%s)',
                    "{$this->column(self::ACTIVITIES_TABLE)}.`trackable_type`",
                    "{$this->column(self::ACTIVITIES_TABLE)}.`trackable_id`",
                    $placeholders
                ),
                [self::DEAL_TRACKABLE_TYPE, ...$dealIds]
            ),
            'read the deal activities of company ' . $companyId
        );

        $grouped = [];

        foreach ($rows as $row) {
            $trackableId = (int) $row['trackable_id'];

            $grouped[$trackableId] ??= [];
            $grouped[$trackableId][] = $this->toActivityProjection($row);
        }

        return $grouped;
    }

    /**
     * Builds and prepares an activity read, joined to the author, in the list's
     * own order.
     *
     * The `WHERE` body is supplied by the caller because the two readers differ
     * only there: one narrows on the company's own trackable type and id, the
     * other on a set of deal ids. Everything else — the projection, the join and
     * the order — is shared, so the two cannot drift apart.
     *
     * The tables are aliased (`a`, `u`) rather than qualified by their real names.
     * A qualifier has to name the table as the statement wrote it, and this
     * statement writes `` `wp_activities` ``; MySQL creates no alias from the
     * prefix, so `` `activities`.`id` `` after `` FROM `wp_activities` `` is error
     * 1054, "Unknown column 'activities.id'". An explicit alias is the spelling
     * that resolves, and it keeps the prefix in the `FROM` clause alone.
     *
     * @param string $where A clause body with `%s`/`%d` placeholders, qualifying columns as `a.`.
     * @param list<mixed> $args Values for those placeholders, in order.
     *
     * @return string A prepared statement.
     */
    private function activityQuery(string $where, array $args): string
    {
        $activities = $this->column(self::ACTIVITIES_TABLE);
        $users = $this->column(self::USERS_TABLE);

        return $this->prepare(
            sprintf(
                'SELECT %s, %s, %s, %s, %s, %s, %s, %s AS `user_name` '
                . 'FROM %s LEFT JOIN %s ON %s = %s '
                . '%s ORDER BY %s DESC, %s DESC',
                "{$activities}.`id`",
                "{$activities}.`type`",
                "{$activities}.`subject`",
                "{$activities}.`description`",
                "{$activities}.`due_date`",
                "{$activities}.`completed_at`",
                "{$activities}.`trackable_id`",
                "{$users}.`display_name`",
                $this->tableName(self::ACTIVITIES_TABLE),
                $this->tableName(self::USERS_TABLE),
                "{$users}.`ID`",
                "{$activities}.`user_id`",
                $where,
                "{$activities}.`created_at`",
                "{$activities}.`id`"
            ),
            $args
        );
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
        // `stage_id` is the one never null on a real row, so its absence decides.
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
     * Reads one row, or null when there is genuinely no such row.
     *
     * `$wpdb` reports a failed statement and an empty result identically — both
     * are a null from `get_row()` — so the two are told apart by `last_error`. A
     * database fault must not be served to a caller as absence, because a caller
     * reads null as "no such company" and answers 404.
     *
     * @param string $sql A statement with `%d`/`%s` placeholders.
     * @param list<mixed> $args Values for those placeholders, in order.
     * @param string $action Phrased for the failure message.
     *
     * @return array<string, mixed>|null
     *
     * @throws RuntimeException When the statement fails.
     */
    private function findRow(string $sql, array $args, string $action): ?array
    {
        $db = $this->db();

        $row = $db->get_row($this->prepare($sql, $args), self::OUTPUT_ASSOC);

        if ($row === null && $db->last_error !== '') {
            throw new RuntimeException(sprintf('Failed to %s: %s', $action, $db->last_error));
        }

        return is_array($row) ? $row : null;
    }

    /**
     * Reads a set of rows, failing loudly on a broken statement.
     *
     * **Not `$rows === false`.** WordPress's `get_results()` returns an empty
     * array on a failed statement, not `false`: it calls `query()` but ignores the
     * return value, then builds its result from `last_result`, which `flush()` has
     * already set to `array()` (see `wp-includes/class-wpdb.php`, `get_results()`
     * and `flush()`). So a `=== false` test never fires in production, and every
     * broken multi-row read would be reported as "this company has no contacts" —
     * a fault rendered as an ordinary empty result.
     *
     * The failure is read from `last_error` instead, which `flush()` clears at the
     * start of each statement and the driver sets on error. This is the same test
     * {@see findRow()} and `BaseRepository::rowExists()` use, so all three reads in
     * the module now agree on how a fault is recognised.
     *
     * @param string $sql An already-prepared statement.
     * @param string $action Phrased for the failure message.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException When the statement fails.
     */
    private function rows(string $sql, string $action): array
    {
        $db = $this->db();

        $rows = $db->get_results($sql, self::OUTPUT_ASSOC);

        if ($db->last_error !== '') {
            throw new RuntimeException(sprintf('Failed to %s: %s', $action, $db->last_error));
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * Binds a statement's placeholders, and guarantees the result is executable.
     *
     * `prepare()` is skipped only when the statement has nothing to bind *and*
     * nothing to bind to. Skipping it on the argument count alone is what makes
     * this dangerous: a statement that still carries a `%d` or `%s` would be
     * returned unbound and handed straight to the driver, where a placeholder is
     * not an error but literal SQL text — `WHERE id = %d` matches nothing, and
     * `%s` inside a quoted string is read as a value. That reads as an ordinary
     * empty result rather than a fault, which is the worst way for it to fail.
     *
     * The two conditions are therefore checked together, and the mismatch is
     * raised rather than served. Every caller here builds its `WHERE` body
     * alongside its own arguments, so a caller falling out of step is a coding
     * error in this file, not something a request can provoke.
     *
     * WordPress's own `prepare()` warns when the query contains no `%` at all
     * (`wp-includes/class-wpdb.php`), which is why the empty-argument path exists
     * at all.
     *
     * @param string $sql A statement with `%d`/`%s` placeholders.
     * @param list<mixed> $args Values for those placeholders, in order.
     *
     * @return string A statement safe to execute.
     *
     * @throws RuntimeException When a statement with placeholders has no values.
     */
    private function prepare(string $sql, array $args): string
    {
        if ($args === []) {
            if (str_contains($sql, '%')) {
                throw new RuntimeException(sprintf(
                    'A company read was built with placeholders but no values to bind them. '
                    . 'The statement was: %s',
                    $sql
                ));
            }

            return $sql;
        }

        $prepared = $this->db()->prepare($sql, ...$args);

        // `prepare()` returns null on a malformed statement. Returning the
        // unbound statement instead would hand `$wpdb` a query with literal `%d`
        // in it, so the failure is raised where it can still name the statement.
        if (! is_string($prepared) || $prepared === '') {
            throw new RuntimeException(sprintf(
                'Failed to bind the parameters of a company read. The statement was: %s',
                $sql
            ));
        }

        return $prepared;
    }

    /**
     * The fully-qualified table name.
     *
     * @param string $table The table name, without the WordPress prefix.
     *
     * @return string
     */
    private function tableName(string $table): string
    {
        return $this->db()->prefix . $table;
    }

    /**
     * A table name, backtick-quoted, for use as a column qualifier.
     *
     * A qualifier has to name the table the way the statement's own `FROM`/`JOIN`
     * spelled it. Those clauses write the **prefixed** name, and MySQL creates no
     * alias from the prefix — so `` `deals`.`id` `` after `` FROM `wp_deals` `` is
     * error 1054, "Unknown column 'deals.id'", even though the table is right
     * there. The prefixed spelling is the one that resolves.
     *
     * The prefix is the site's own configured value, not request input, and it is
     * backtick-escaped here like every other identifier.
     *
     * @param string $table The table name, without the WordPress prefix.
     *
     * @return string
     */
    private function column(string $table): string
    {
        return '`' . $this->escapeIdentifier($this->tableName($table)) . '`';
    }

    /**
     * Wrap an identifier in backticks, escaping any backtick inside it.
     *
     * Names come from the prefix and a constant rather than from input, but a
     * prefix containing a backtick would end the identifier early and name a
     * different table, and `$wpdb` offers no helper for this.
     *
     * @param string $identifier
     *
     * @return string
     */
    private function escapeIdentifier(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }

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

    /**
     * The `$wpdb` handle, resolved on first use.
     *
     * Lazy rather than constructor-injected, because WordPress owns this object:
     * a constructor type-hint on `wpdb` would resolve through the container's
     * auto-wiring to a freshly constructed, unconnected instance rather than the
     * site's global one. Resolving here also means a repository built during
     * `register()` is safe, which is when the provider builds one.
     *
     * @return wpdb
     *
     * @throws RuntimeException When there is no handle to resolve.
     */
    private function db(): wpdb
    {
        if ($this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }

        if (! isset($GLOBALS['wpdb']) || ! $GLOBALS['wpdb'] instanceof wpdb) {
            throw new RuntimeException(
                'No $wpdb instance is available. A repository cannot run outside WordPress, and it '
                . 'cannot run during a hook that fires before the database is loaded.'
            );
        }

        return $this->wpdb = $GLOBALS['wpdb'];
    }
}
