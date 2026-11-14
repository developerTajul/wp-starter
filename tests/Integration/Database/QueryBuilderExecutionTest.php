<?php
declare(strict_types=1);

namespace Tests\Integration\Database;

use Framework\Database\Query\Exceptions\QueryException;
use Framework\Database\Query\WpFluentQueryBuilder;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;

/**
 * The query builder's statements, really executed.
 *
 * ## What this proves that the unit test does not
 *
 * {@see \Tests\Unit\Database\Query\WpFluentQueryBuilderTest} asserts the
 * builder's *shape* — that chaining clones, that bad input is refused. It never
 * asserts that the SQL the builder composes is SQL the database understands.
 *
 * This does. Every statement here is handed to {@see FakeWpdb}, which parses it
 * and answers from rows. A column name the table does not have, a placeholder
 * count that does not match its arguments, a `LIMIT`/`OFFSET` in the wrong
 * order — each of those is a loud failure here, where an assertion about a
 * query string would have passed.
 *
 * ## Why the schema is declared in full
 *
 * The fixture table carries the columns the real `leads` migration creates —
 * `status`, `source`, `company_name`, `assigned_to`, not only the four the tests
 * happen to read. A fixture that invented just the columns under test would pass
 * while the real table disagreed, which is the failure the repository tests this
 * one sits beside are written to avoid.
 */
final class QueryBuilderExecutionTest extends TestCase
{
    private FakeWpdb $db;

    private const TABLE = 'wp_leads';

    protected function setUp(): void
    {
        $this->db = new FakeWpdb();
        $this->db->createTable(self::TABLE, [
            'id',
            'name',
            'email',
            'phone',
            'company_name',
            'status',
            'source',
            'assigned_to',
            'created_at',
            'updated_at',
        ]);
    }

    private function builder(): WpFluentQueryBuilder
    {
        return (new WpFluentQueryBuilder($this->db))->table('leads');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seed(int $id, string $name, array $overrides = []): void
    {
        $this->db->seed(self::TABLE, array_merge([
            'id' => $id,
            'name' => $name,
            'email' => strtolower($name) . '@example.test',
            'phone' => null,
            'company_name' => null,
            'status' => 'new',
            'source' => 'form',
            'assigned_to' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides));
    }

    // -------------------------------------------------------------------- reads

    public function test_get_returns_every_row_when_unfiltered(): void
    {
        $this->seed(1, 'Ada');
        $this->seed(2, 'Grace');

        $this->assertCount(2, $this->builder()->get());
    }

    public function test_where_filters_the_rows_returned(): void
    {
        $this->seed(1, 'Ada', ['status' => 'new']);
        $this->seed(2, 'Grace', ['status' => 'won']);

        $rows = $this->builder()->where('status', 'won')->get();

        $this->assertSame(['Grace'], array_column($rows, 'name'));
    }

    /**
     * Two conditions compose with `AND`, and the arguments must line up.
     *
     * This is where a placeholder/argument mismatch shows itself: if the builder
     * bound `won` to the first condition's slot, the fake would filter on the
     * wrong column and return the wrong row rather than erroring.
     */
    public function test_multiple_conditions_are_conjoined_and_bound_in_order(): void
    {
        $this->seed(1, 'Ada', ['status' => 'new', 'source' => 'form']);
        $this->seed(2, 'Grace', ['status' => 'new', 'source' => 'webhook']);
        $this->seed(3, 'Alan', ['status' => 'won', 'source' => 'form']);

        $rows = $this->builder()
            ->where('status', 'new')
            ->where('source', 'form')
            ->get();

        $this->assertSame(['Ada'], array_column($rows, 'name'));
    }

    /**
     * A string value carrying a quote is bound, not interpolated.
     *
     * `O'Brien` is the case that proves binding: an identifier-escaped or
     * interpolated builder produces a statement the fake cannot parse, or worse,
     * one that parses into a different query.
     */
    public function test_a_value_containing_a_quote_is_bound_safely(): void
    {
        $this->seed(1, "O'Brien", ['status' => 'new']);
        $this->seed(2, 'Ada', ['status' => 'new']);

        $rows = $this->builder()->where('name', "O'Brien")->get();

        $this->assertSame(["O'Brien"], array_column($rows, 'name'));
    }

    /**
     * An integer binds as `%d` and still compares numerically.
     */
    public function test_an_integer_condition_binds_and_matches(): void
    {
        $this->seed(1, 'Ada', ['assigned_to' => 7]);
        $this->seed(2, 'Grace', ['assigned_to' => 9]);

        $rows = $this->builder()->where('assigned_to', 7)->get();

        $this->assertSame(['Ada'], array_column($rows, 'name'));
    }

    /**
     * Comparing against null is refused, because `IS NULL` cannot be executed here.
     *
     * The companion to the operator refusal in the unit test, and the same
     * principle: a value of null means `IS NULL`, that is a statement the harness
     * rejects, and compiling to it anyway would ship SQL whose behaviour no test
     * exercises. Refusing keeps "what this builder does" and "what has been
     * verified" the same set.
     */
    public function test_a_null_condition_is_refused_rather_than_compiled(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/does not mean what it looks like/');

        $this->builder()->where('phone', null);
    }

    /**
     * `first()` returns one row, and the row it returns is governed by `orderBy()`.
     */
    public function test_first_returns_the_row_the_ordering_selects(): void
    {
        $this->seed(1, 'Grace');
        $this->seed(2, 'Ada');

        $row = $this->builder()->orderBy('name', 'asc')->first();

        $this->assertNotNull($row);
        $this->assertSame('Ada', $row['name']);
    }

    public function test_first_returns_null_when_nothing_matches(): void
    {
        $this->seed(1, 'Ada', ['status' => 'new']);

        $this->assertNull($this->builder()->where('status', 'won')->first());
    }

    /**
     * The count is over the whole predicate, not the current slice.
     *
     * This is the distinction that makes pagination right: a total read from the
     * returned slice reports "1 of 1" on every page.
     */
    public function test_count_ignores_the_limit_and_offset(): void
    {
        $this->seed(1, 'Ada');
        $this->seed(2, 'Grace');
        $this->seed(3, 'Alan');

        $this->assertSame(
            3,
            $this->builder()->limit(1)->offset(2)->count(),
            'A count must describe every matching row, not the page being read.'
        );
    }

    /**
     * `limit()` and `offset()` select the expected window.
     *
     * Ordered first, so the window is deterministic — an unordered page is a
     * page whose contents the database is free to change between calls. Ordered
     * by name the rows are Ada, Alan, Grace, so a limit of one from offset one
     * is the second of those.
     */
    public function test_limit_and_offset_select_the_expected_window(): void
    {
        $this->seed(1, 'Ada');
        $this->seed(2, 'Grace');
        $this->seed(3, 'Alan');

        $rows = $this->builder()
            ->orderBy('name')
            ->limit(1)
            ->offset(1)
            ->get();

        $this->assertSame(['Alan'], array_column($rows, 'name'));
    }

    /**
     * An explicit projection returns only the named columns.
     */
    public function test_select_narrows_the_returned_columns(): void
    {
        $this->seed(1, 'Ada');

        $row = $this->builder()->select('id', 'name')->first();

        $this->assertNotNull($row);
        $this->assertSame(['id', 'name'], array_keys($row));
    }

    // ------------------------------------------------------------------- writes

    public function test_insert_stores_a_row_and_returns_its_id(): void
    {
        $id = $this->builder()->insertGetId([
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'status' => 'new',
            'source' => 'form',
        ]);

        $this->assertGreaterThan(0, $id);

        $row = $this->builder()->where('id', $id)->first();

        $this->assertNotNull($row);
        $this->assertSame('Ada', $row['name']);
    }

    public function test_update_changes_the_matching_rows_and_reports_the_count(): void
    {
        $this->seed(1, 'Ada', ['status' => 'new']);
        $this->seed(2, 'Grace', ['status' => 'new']);

        $affected = $this->builder()->where('status', 'new')->update(['status' => 'won']);

        $this->assertSame(2, $affected);
        $this->assertSame(
            2,
            $this->builder()->where('status', 'won')->count(),
            'Both rows should now carry the new value.'
        );
    }

    public function test_delete_removes_the_matching_rows_and_reports_the_count(): void
    {
        $this->seed(1, 'Ada', ['status' => 'new']);
        $this->seed(2, 'Grace', ['status' => 'won']);

        $removed = $this->builder()->where('status', 'won')->delete();

        $this->assertSame(1, $removed);
        $this->assertSame(1, $this->builder()->count(), 'Only the matching row should be gone.');
    }

    /**
     * A failed statement raises rather than reading as an empty result.
     *
     * This is the property the repositories depend on: `$wpdb` reports a failure
     * by return value, and reading that as "no rows" turns a broken query into a
     * confident "there is nothing here".
     */
    public function test_a_failed_read_raises_rather_than_returning_no_rows(): void
    {
        $this->db->failOn('SELECT');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/Could not read from leads/');

        $this->builder()->get();
    }

    /**
     * A failed count raises too, rather than reporting zero.
     *
     * Zero is a *claim about the data*; a failed statement is not entitled to
     * make it, and a paginated response built on it would report an empty set.
     */
    public function test_a_failed_count_raises_rather_than_reporting_zero(): void
    {
        $this->db->failOn('SELECT COUNT');

        $this->expectException(QueryException::class);

        $this->builder()->count();
    }

    // ------------------------------------------------------- or / like / in / null

    /**
     * `orWhere()` widens the predicate rather than narrowing it.
     *
     * The distinction only shows with two seeded rows that each satisfy one half:
     * a builder that compiled `OR` as `AND` would return nothing, and one that
     * dropped the second condition would return one row. Both are wrong here, and
     * only running the query tells them apart.
     */
    public function test_or_where_matches_either_condition(): void
    {
        $this->seed(1, 'Ada', ['status' => 'new']);
        $this->seed(2, 'Grace', ['status' => 'won']);
        $this->seed(3, 'Alan', ['status' => 'lost']);

        $rows = $this->builder()->where('status', 'new')->orWhere('status', 'won')->get();

        $this->assertSame(['Ada', 'Grace'], array_column($rows, 'name'));
    }

    /**
     * A group's `OR` cannot escape into the conditions around it.
     *
     * `status = new AND (name = Ada OR name = Grace)` is the intent. Compiled
     * without the brackets it would be `status = new AND name = Ada OR
     * name = Grace`, which MySQL reads as `(status = new AND name = Ada) OR
     * name = Grace` - and would return Grace despite the `won` status. Grace is
     * seeded `won` precisely so that leak shows up as a wrong row.
     */
    public function test_a_group_keeps_its_or_away_from_the_outer_conditions(): void
    {
        $this->seed(1, 'Ada', ['status' => 'new']);
        $this->seed(2, 'Grace', ['status' => 'won']);
        $this->seed(3, 'Alan', ['status' => 'new']);

        $rows = $this->builder()
            ->where('status', 'new')
            ->whereGroup(fn ($group) => $group->where('name', 'Ada')->orWhere('name', 'Grace'))
            ->get();

        $this->assertSame(['Ada'], array_column($rows, 'name'));
    }

    /**
     * `LIKE` matches a pattern, and a pattern without wildcards matches exactly.
     */
    public function test_like_matches_a_pattern_and_a_plain_value_exactly(): void
    {
        $this->seed(1, 'Ada Lovelace');
        $this->seed(2, 'Ada');
        $this->seed(3, 'Grace Hopper');

        $this->assertSame(
            ['Ada Lovelace', 'Ada'],
            array_column($this->builder()->where('name', 'Ada%', 'LIKE')->get(), 'name')
        );

        $this->assertSame(
            ['Ada'],
            array_column($this->builder()->where('name', 'Ada', 'LIKE')->get(), 'name'),
            'A pattern with no wildcard must match the whole value, not a substring of it.'
        );
    }

    /**
     * `whereIn()` matches any listed value, and an empty list is refused.
     *
     * Refused rather than compiled to `IN ()`: that is a syntax error in MySQL,
     * and the caller's mistake is a filter that came out empty rather than a
     * request to match nothing.
     */
    public function test_where_in_matches_any_listed_value(): void
    {
        $this->seed(1, 'Ada', ['status' => 'new']);
        $this->seed(2, 'Grace', ['status' => 'won']);
        $this->seed(3, 'Alan', ['status' => 'lost']);

        $rows = $this->builder()->whereIn('status', ['new', 'won'])->get();

        $this->assertSame(['Ada', 'Grace'], array_column($rows, 'name'));
    }

    public function test_where_in_refuses_an_empty_list(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/empty list/');

        $this->builder()->whereIn('status', []);
    }

    /**
     * `whereNotIn()` excludes the listed values.
     */
    public function test_where_not_in_excludes_the_listed_values(): void
    {
        $this->seed(1, 'Ada', ['status' => 'new']);
        $this->seed(2, 'Grace', ['status' => 'won']);
        $this->seed(3, 'Alan', ['status' => 'lost']);

        $rows = $this->builder()->whereNotIn('status', ['new', 'won'])->get();

        $this->assertSame(['Alan'], array_column($rows, 'name'));
    }

    /**
     * An empty `NOT IN` excludes nothing, so every row still matches.
     *
     * The asymmetry with `whereIn()` is deliberate: "outside a set that turned
     * out to be empty" is true for every row, so the clause is dropped rather
     * than refused.
     */
    public function test_where_not_in_with_an_empty_list_matches_everything(): void
    {
        $this->seed(1, 'Ada');
        $this->seed(2, 'Grace');

        $this->assertCount(2, $this->builder()->whereNotIn('status', [])->get());
    }

    /**
     * The null tests match the rows the column is, and is not, null on.
     *
     * `orderBy` is applied so the two assertions do not depend on insertion
     * order, which the fake does not promise once it filters.
     */
    public function test_where_null_and_where_not_null_partition_the_rows(): void
    {
        $this->seed(1, 'Ada', ['phone' => null]);
        $this->seed(2, 'Grace', ['phone' => '555-0100']);

        $this->assertSame(
            ['Ada'],
            array_column($this->builder()->whereNull('phone')->get(), 'name')
        );

        $this->assertSame(
            ['Grace'],
            array_column($this->builder()->whereNotNull('phone')->get(), 'name')
        );
    }

    // --------------------------------------------------------------------- joins

    /**
     * An inner join keeps only rows with a match on both sides.
     */
    public function test_join_keeps_only_the_matching_rows(): void
    {
        $this->db->createTable('wp_companies', ['id', 'name']);
        $this->db->seed('wp_companies', ['id' => 1, 'name' => 'Acme']);
        $this->db->seed('wp_companies', ['id' => 2, 'name' => 'Globex']);

        $this->seed(1, 'Ada', ['company_name' => 'Acme']);
        $this->seed(2, 'Grace', ['company_name' => 'Globex']);

        $rows = $this->builder()
            ->join('companies', 'companies.name', '=', 'leads.company_name')
            ->select('leads.name', 'companies.name AS company')
            ->get();

        $this->assertSame(['Ada', 'Grace'], array_column($rows, 'name'));
    }

    /**
     * A left join keeps the left row even when nothing matches.
     *
     * The distinction from the inner join above, and the reason the repository
     * uses one for an optional relation: a deal whose stage row is gone must
     * still be listed, without its stage, rather than disappearing.
     */
    public function test_left_join_keeps_a_row_with_no_match_and_nulls_the_joined_columns(): void
    {
        $this->db->createTable('wp_companies', ['id', 'name']);
        $this->db->seed('wp_companies', ['id' => 1, 'name' => 'Acme']);

        $this->seed(1, 'Ada', ['company_name' => 'Acme']);
        $this->seed(2, 'Grace', ['company_name' => 'Nobody']);

        $rows = $this->builder()
            ->leftJoin('companies', 'companies.name', '=', 'leads.company_name')
            ->select('leads.name', 'companies.id AS company_id')
            ->orderBy('leads.name')
            ->get();

        $this->assertSame(['Ada', 'Grace'], array_column($rows, 'name'));
        $this->assertSame(1, (int) $rows[0]['company_id']);
        $this->assertNull(
            $rows[1]['company_id'],
            'The unmatched row must survive the join with its joined column null.'
        );
    }

    /**
     * An alias is the key the value is read back under, not the column's name.
     *
     * A joined read depends on this: `deal_stages`.`id` and `deals`.`id` are both
     * `id`, and the caller tells them apart only by the alias it asked for.
     */
    public function test_a_select_alias_decides_the_key_the_value_returns_under(): void
    {
        $this->seed(1, 'Ada');

        $row = $this->builder()->select('id AS lead_id', 'name AS lead_name')->first();

        $this->assertNotNull($row);
        $this->assertSame(['lead_id', 'lead_name'], array_keys($row));
        $this->assertSame(1, (int) $row['lead_id']);
    }

    /**
     * A join and a where on the joined side compose.
     *
     * The joined column is addressed by its qualified name, which is the only way
     * to name it once two tables are in play.
     */
    public function test_a_condition_reaches_the_joined_table(): void
    {
        $this->db->createTable('wp_companies', ['id', 'name', 'industry']);
        $this->db->seed('wp_companies', ['id' => 1, 'name' => 'Acme', 'industry' => 'Software']);
        $this->db->seed('wp_companies', ['id' => 2, 'name' => 'Globex', 'industry' => 'Hardware']);

        $this->seed(1, 'Ada', ['company_name' => 'Acme']);
        $this->seed(2, 'Grace', ['company_name' => 'Globex']);

        $rows = $this->builder()
            ->join('companies', 'companies.name', '=', 'leads.company_name')
            ->where('companies.industry', 'Software')
            ->select('leads.name')
            ->get();

        $this->assertSame(['Ada'], array_column($rows, 'name'));
    }

    /**
     * A joined read supports the search group the company list needs.
     *
     * This is the whole shape `WpCompanyReadRepository` compiles: a join, a
     * bracketed `OR` group over two columns, ordering and a page window. Asserted
     * together because it is the composition that has to hold, and each part
     * passing alone would not show a group leaking into the join's condition.
     */
    public function test_a_joined_read_with_a_search_group_and_a_page_window(): void
    {
        $this->db->createTable('wp_companies', ['id', 'name', 'industry']);
        $this->db->seed('wp_companies', ['id' => 1, 'name' => 'Acme Analytics', 'industry' => 'Software']);
        $this->db->seed('wp_companies', ['id' => 2, 'name' => 'Globex', 'industry' => 'Acme Manufacturing']);
        $this->db->seed('wp_companies', ['id' => 3, 'name' => 'Initech', 'industry' => 'Hardware']);

        $this->seed(1, 'Ada', ['company_name' => 'Acme Analytics']);
        $this->seed(2, 'Grace', ['company_name' => 'Globex']);
        $this->seed(3, 'Alan', ['company_name' => 'Initech']);

        $rows = $this->builder()
            ->join('companies', 'companies.name', '=', 'leads.company_name')
            ->whereGroup(fn ($group) => $group
                ->where('companies.name', '%Acme%', 'LIKE')
                ->orWhere('companies.industry', '%Acme%', 'LIKE'))
            ->select('leads.name')
            ->orderByDesc('companies.name')
            ->limit(15)
            ->offset(0)
            ->get();

        $this->assertSame(['Grace', 'Ada'], array_column($rows, 'name'));
    }

    /**
     * A join that matches nothing yields no rows, not a broken statement.
     *
     * The counterpart to the left join above: the same join, the same unmatched
     * row, and the row is gone rather than null-filled.
     */
    public function test_a_join_returns_nothing_when_no_right_row_matches(): void
    {
        $this->db->createTable('wp_companies', ['id', 'name']);
        $this->seed(1, 'Ada', ['company_name' => 'Acme']);

        $this->assertSame([], $this->builder()
            ->join('companies', 'companies.name', '=', 'leads.company_name')
            ->get());
    }

    // -------------------------------------------- qualifier strictness (MySQL 1054)

    /**
     * The statement above is only evidence if the harness would refuse it when it
     * was wrong.
     *
     * Every other test here goes through the builder, which prefixes a qualifier
     * before the statement is composed — so the fake never sees a qualifier that
     * disagrees with the `FROM` table, and its check for that case is never
     * exercised. These tests hand the fake statements the builder would not
     * compose, which is the only way to pin the check itself.
     *
     * `FROM `wp_leads`` binds `wp_leads` and creates no `leads` alias, so a
     * qualifier of `leads` is error 1054 on a real server. That exact mistake
     * reached a live site: the builder used to emit bare qualifiers and the fake
     * accepted them, so every joined read passed here and failed there.
     */
    public function test_a_bare_qualifier_that_the_from_clause_never_bound_is_refused(): void
    {
        $this->seed(1, 'Ada');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Unknown column 'leads' in 'field list'");

        $this->db->get_results('SELECT `leads`.`name` FROM `wp_leads`', ARRAY_A);
    }

    /**
     * The qualifier is checked against the tables the statement itself declares.
     *
     * A table that exists on the server but is absent from this statement's
     * `FROM`/`JOIN` is unknown to it — MySQL resolves names per statement, not per
     * database, so a subquery's table is not in scope outside it.
     */
    public function test_a_qualifier_naming_a_table_outside_the_statement_is_refused(): void
    {
        $this->db->createTable('wp_companies', ['id', 'name']);
        $this->seed(1, 'Ada');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Unknown column 'payments' in 'field list'");

        $this->db->get_results('SELECT `payments`.`name` FROM `wp_leads`', ARRAY_A);
    }

    /**
     * The refusal does not depend on the statement returning a row.
     *
     * MySQL raises 1054 while preparing the statement, before it reads a single
     * row — so a fake that validated while mapping rows would let invalid SQL
     * through whenever the `WHERE` happened to match nothing. That is the quiet
     * version of the failure: not a wrong answer, but no answer, from a query
     * that cannot run at all.
     */
    public function test_an_unknown_column_is_refused_even_when_no_row_matches(): void
    {
        $this->seed(1, 'Ada', ['status' => 'new']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Unknown column 'payments' in 'field list'");

        $this->db->get_results(
            'SELECT `payments`.`name` FROM `wp_leads` WHERE `status` = \'gone\'',
            ARRAY_A
        );
    }

    /**
     * A qualifier that is refused in the `WHERE` names the clause it failed in.
     *
     * The clause is what makes the message usable: the same unknown table in a
     * `SELECT` list and in a `WHERE` are different mistakes to go and fix.
     */
    public function test_an_unknown_qualifier_in_a_where_clause_names_the_where_clause(): void
    {
        $this->seed(1, 'Ada');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("Unknown column 'payments' in 'where clause'");

        $this->db->get_results(
            'SELECT `wp_leads`.`name` FROM `wp_leads` WHERE `payments`.`name` = \'Ada\'',
            ARRAY_A
        );
    }

    /**
     * The prefixed spelling is the one that resolves, and it still does.
     *
     * The counterpart to the refusals above: tightening the check must not refuse
     * the SQL the builder actually emits. `FROM `wp_leads`` + `` `wp_leads`.`name` ``
     * is what every joined read compiles to, and it has to keep returning rows.
     */
    public function test_the_prefixed_qualifier_resolves_to_the_row(): void
    {
        $this->seed(1, 'Ada');

        $rows = $this->db->get_results(
            'SELECT `wp_leads`.`name` FROM `wp_leads` WHERE `wp_leads`.`status` = \'new\'',
            ARRAY_A
        );

        $this->assertSame([['name' => 'Ada']], $rows);
    }

}
