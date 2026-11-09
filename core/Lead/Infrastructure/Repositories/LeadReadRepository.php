<?php
declare(strict_types=1);

namespace Core\Lead\Infrastructure\Repositories;

use Core\Lead\Application\Contracts\LeadQueryContract;
use Core\Lead\Domain\Entities\LeadEntity;
use Core\Lead\Domain\ReadModels\LeadProjectionDTO;
use Core\Lead\Domain\ValueObjects\LeadFilter;
use Core\Lead\Infrastructure\Mappers\LeadEntityMapper;
use Core\Shared\Domain\DataObjects\PaginatedResult;
use Framework\Database\Query\QueryBuilderInterface;
use RuntimeException;
use wpdb;

/**
 * Fluent-builder read side of the lead port.
 *
 * Owns the table and column names and the fact that the engine is MySQL; nothing
 * above Infrastructure sees a `SELECT`.
 *
 * A failed statement throws a {@see RuntimeException} rather than returning null,
 * because `$wpdb` reports a broken query and an empty result identically. Callers
 * read null from `findEntityById()` as "no lead has that id" (a 404), so a
 * database fault must not be served as absence; the builder raises
 * {@see \Framework\Database\Query\Exceptions\QueryException} for the same reason,
 * which the catch here re-raises as the `RuntimeException` this port's callers
 * already expect.
 *
 * @implements LeadQueryContract
 */
final class LeadReadRepository implements LeadQueryContract
{
    /** Table suffix appended to `$wpdb->prefix`; matches the create_leads_table migration. */
    public const TABLE_SUFFIX = 'leads';

    /**
     * Columns the read model is built from, in mapper order.
     *
     * Explicit rather than `SELECT *`: the projection is a promise about what is
     * read, and `SELECT *` would widen it silently whenever a column is added.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'id', 'name', 'email', 'phone', 'company_name', 'status',
        'source', 'assigned_to', 'created_at', 'updated_at',
    ];

    /**
     * @param QueryBuilderInterface $query  The fluent builder, bound to the
     *                                      WordPress connection by its provider.
     * @param LeadEntityMapper      $mapper Array to entity translation.
     * @param wpdb|null             $wpdb   Only {@see getTableName()} needs a
     *                                      handle; resolved lazily.
     */
    public function __construct(
        private readonly QueryBuilderInterface $query,
        private readonly LeadEntityMapper $mapper,
        private ?wpdb $wpdb = null,
    ) {}

    /**
     * Fully-qualified table name.
     *
     * Public so a seeder or admin screen need not re-derive it from the prefix.
     *
     * `$wpdb->prefix` is read rather than configured, because a multisite subsite
     * has its own prefix on the same connection.
     */
    public function getTableName(): string
    {
        return $this->db()->prefix . self::TABLE_SUFFIX;
    }

    // ------------------------------------------------------------------ reads

    /**
     * {@inheritDoc}
     *
     * Filtering happens in SQL because LIMIT runs before PHP sees the rows: a
     * page filtered in memory would hold only its own matches, so page 1 could
     * show one row under a count of two hundred.
     *
     * Both the count and the page come from the same predicate, built once in
     * {@see applyFilter()}, so the total and the slice cannot describe different
     * filters.
     */
    public function getFilteredLeads(LeadFilter $filter): PaginatedResult
    {
        $perPage = $filter->perPage;
        $page = $this->currentPage();

        // Counts every page, not this slice; `count($rows)` would report "1 of 1"
        // on each page.
        $total = $this->applyFilter($filter)->count();

        $rows = $this->applyFilter($filter)
            ->select(...self::COLUMNS)
            // `id` tie-breaks equal `created_at` values; without it a row can
            // appear on two pages while another is skipped.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        return PaginatedResult::fromSlice(
            array_map(fn (array $row): LeadProjectionDTO => $this->mapper->toProjection($row), $rows),
            $total,
            $perPage,
            $page
        );
    }

    /**
     * {@inheritDoc}
     *
     * Counts rather than loads: the caller is a guard checking a parent exists and
     * has no use for the row.
     */
    public function exists(int $id): bool
    {
        return $this->query->table(self::TABLE_SUFFIX)->where('id', $id)->count() > 0;
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?LeadProjectionDTO
    {
        $row = $this->findRow(fn (QueryBuilderInterface $query): QueryBuilderInterface => $query->where('id', $id));

        return $row === null ? null : $this->mapper->toProjection($row);
    }

    /**
     * {@inheritDoc}
     */
    public function findEntityById(int $id): ?LeadEntity
    {
        $row = $this->findRow(fn (QueryBuilderInterface $query): QueryBuilderInterface => $query->where('id', $id));

        return $row === null ? null : $this->mapper->toEntity($row);
    }

    /**
     * {@inheritDoc}
     *
     * A null or blank email returns null rather than querying: the caller asks
     * "does a lead with this email exist", and for one that arrived without an
     * email the answer is no. The empty-string case matters most, since leads
     * often store `''` rather than NULL and a lookup would otherwise match the
     * first blank-email lead and report a false duplicate.
     */
    public function findEntityByEmail(?string $email): ?LeadEntity
    {
        return $this->findEntityBy('email', $email);
    }

    /**
     * {@inheritDoc}
     *
     * See {@see findEntityByEmail()} for why a blank value is not looked up.
     */
    public function findEntityByPhone(?string $phone): ?LeadEntity
    {
        return $this->findEntityBy('phone', $phone);
    }

    // ------------------------------------------------------------------ internals

    /**
     * One entity matching a single nullable column, or null.
     *
     * The `match` keeps `$column` to the two literals above, so it never reaches
     * a query from input; a third caller must add its column here first.
     *
     * @param string      $column `'email'` or `'phone'`.
     * @param string|null $value
     */
    private function findEntityBy(string $column, ?string $value): ?LeadEntity
    {
        $column = match ($column) {
            'email' => 'email',
            'phone' => 'phone',
            default => throw new RuntimeException(sprintf(
                'Unknown lead lookup column [%s]. Only the unique contact columns can be looked up '
                . 'this way; add the column here if a third is genuinely needed.',
                $column
            )),
        };

        // Trimmed before the emptiness test, because a form field that arrived as
        // a single space is a blank field, and matching it would find whichever
        // row happens to hold a space.
        $value = $value === null ? null : trim($value);

        if ($value === null || $value === '') {
            return null;
        }

        $row = $this->findRow(
            fn (QueryBuilderInterface $query): QueryBuilderInterface => $query->where($column, $value)
        );

        return $row === null ? null : $this->mapper->toEntity($row);
    }

    /**
     * One row matching the given conditions, or null when there is genuinely no
     * row.
     *
     * The builder distinguishes a failed statement from an empty result by
     * throwing, so a null here is always "no such row" - the ambiguity the
     * `$wpdb` version had to resolve by inspecting `last_error` is resolved
     * before this method sees it.
     *
     * @param callable(QueryBuilderInterface): QueryBuilderInterface $conditions
     *
     * @return array<string, mixed>|null
     */
    private function findRow(callable $conditions): ?array
    {
        return $conditions(
            $this->query->table(self::TABLE_SUFFIX)->select(...self::COLUMNS)
        )->first();
    }

    /**
     * The builder carrying a filter's conditions, and nothing else.
     *
     * Conditions are added with the builder's own vocabulary rather than as SQL
     * fragments, so each value is bound as a parameter and none of them is
     * interpolated.
     *
     * Search has no condition when the term is blank, rather than a `1 = 1`
     * sentinel: an absent clause means "everything" in a way no parser can
     * reinterpret, and the builder treats a query with no `WHERE` as exactly
     * that.
     *
     * @param LeadFilter $filter
     *
     * @return QueryBuilderInterface
     */
    private function applyFilter(LeadFilter $filter): QueryBuilderInterface
    {
        $query = $this->query->table(self::TABLE_SUFFIX);

        if ($filter->status !== null) {
            $query = $query->where('status', $filter->status->value);
        }

        if ($filter->source !== null && trim($filter->source) !== '') {
            $query = $query->where('source', trim($filter->source));
        }

        if ($filter->search !== null && trim($filter->search) !== '') {
            // `esc_like()` runs before the wildcards are added, so a term
            // containing `%` or `_` is matched literally rather than as a
            // wildcard; adding them first would escape the wildcards too.
            $query = $query->where('name', '%' . $this->escapeLike(trim($filter->search)) . '%', 'LIKE');
        }

        return $query;
    }

    /**
     * Neutralise the `LIKE` metacharacters in a term.
     *
     * The counterpart to `$wpdb->esc_like()`, which this class no longer needs a
     * handle for: the escaping rule is a property of MySQL's `LIKE`, not of the
     * connection, and the builder binds the escaped pattern as a parameter.
     *
     * @param string $term
     *
     * @return string
     */
    private function escapeLike(string $term): string
    {
        return addcslashes($term, '%_\\');
    }

    /**
     * The page being requested, from the query string.
     *
     * `LeadFilter` carries no page, and reading it here keeps that value object
     * free of transport state. `$_GET` rather than the framework Request because
     * this class sits in `core/` and cannot name `Framework\Http\Request`.
     *
     * A missing or unparseable value is page 1, not an error: a bad page in a URL
     * is someone typing, not a fault.
     */
    private function currentPage(): int
    {
        $page = $_GET['page'] ?? null;

        if (!is_scalar($page)) {
            return 1;
        }

        return max(1, (int) $page);
    }

    /**
     * The `$wpdb` handle, resolved on first use.
     *
     * Kept only for {@see getTableName()}, which reports the stored table's real
     * name rather than a prefix a caller would have to re-derive. Every statement
     * goes through the builder.
     *
     * Lazy because the repository may be constructed during `register()`, before
     * WordPress loads the global; a constructor default would capture null and
     * stay that way.
     */
    private function db(): wpdb
    {
        if ($this->wpdb === null) {
            $this->wpdb = $GLOBALS['wpdb'] ?? null;

            if (!$this->wpdb instanceof wpdb) {
                throw new RuntimeException(
                    'There is no $wpdb instance. A lead repository cannot run outside WordPress, '
                    . 'and it cannot run during a hook that fires before the database is loaded.'
                );
            }
        }

        return $this->wpdb;
    }
}
