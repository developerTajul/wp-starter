<?php
declare(strict_types=1);

namespace Tests\Unit\Database\Query;

use Framework\Database\Query\Exceptions\QueryException;
use Framework\Database\Query\WpFluentQueryBuilder;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;

/**
 * The query builder's structural guarantees, asserted without a database.
 *
 * ## Why this is a *unit* test when the builder clearly talks to `$wpdb`
 *
 * The builder is handed a {@see FakeWpdb}, so the statements really run — but
 * nothing asserted here depends on rows coming back. What is under test is the
 * *shape* of the class: that chaining cannot mutate the instance it started
 * from, that a rejected operator or column name is refused before any SQL is
 * composed, and that `first()` does not leave a `LIMIT` behind.
 *
 * Those are the failures a row-level test would not catch, because a mutable
 * builder returns exactly the right rows for the query that ran last — it is
 * the *earlier* query, the one built from the same base, that quietly returns
 * the wrong answer. Splitting them out means a broken immutability guarantee
 * fails here, in a test named for it, rather than surfacing as a mysterious
 * wrong-page bug three classes away.
 *
 * ## The delegation predicates below are the important ones
 *
 * `test_chaining_does_not_mutate_the_instance_it_started_from` is the single
 * most valuable assertion in this file. It is written against the *observable*
 * behaviour — run both builders, read two different rows — rather than against
 * object identity alone, because `assertNotSame` passes for a clone that shares
 * a reference to the same array. Running both is what actually proves it.
 */
final class WpFluentQueryBuilderTest extends TestCase
{
    private FakeWpdb $db;

    private const TABLE = 'wp_leads';

    protected function setUp(): void
    {
        $this->db = new FakeWpdb();
        $this->db->createTable(self::TABLE, ['id', 'name', 'email', 'status', 'source']);
    }

    /**
     * A builder already pointed at the leads table in this fixture.
     */
    private function builder(): WpFluentQueryBuilder
    {
        return (new WpFluentQueryBuilder($this->db))->table('leads');
    }

    /**
     * Seed a row, so a test can tell two queries apart by what they return.
     */
    private function seed(int $id, string $name, string $status, string $source = 'form'): void
    {
        $this->db->seed(self::TABLE, [
            'id' => $id,
            'name' => $name,
            'email' => strtolower($name) . '@example.test',
            'status' => $status,
            'source' => $source,
        ]);
    }

    // -------------------------------------------------------------- immutability

    /**
     * The guarantee the whole interface is built around.
     *
     * Two builders are derived from one base, each with a different condition.
     * If `where()` mutated the receiver, both would end up carrying both
     * conditions and both reads would return the same (wrong) rows. So the test
     * runs both and reads the rows back, rather than only comparing identity —
     * identity alone would not catch a shared array reference.
     */
    public function test_chaining_does_not_mutate_the_instance_it_started_from(): void
    {
        $this->seed(1, 'Ada', 'new');
        $this->seed(2, 'Grace', 'won');

        $base = $this->builder()->where('source', 'form');

        $news = $base->where('status', 'new');
        $wons = $base->where('status', 'won');

        $this->assertNotSame($base, $news, 'where() must return a new builder.');
        $this->assertNotSame($news, $wons, 'Two chains must be two builders.');

        $this->assertSame(
            ['Ada'],
            array_column($news->get(), 'name'),
            'The first chain must keep only its own condition.'
        );
        $this->assertSame(
            ['Grace'],
            array_column($wons->get(), 'name'),
            'The second chain must not inherit the first chain\'s condition.'
        );
    }

    /**
     * The base builder is genuinely untouched, not merely un-reused.
     *
     * The previous test proves the two chains do not interfere. This proves the
     * *base* still means what it meant before either chain existed — which is
     * the property that makes a pre-built base worth holding at all.
     */
    public function test_the_base_builder_still_matches_everything_after_being_chained_from(): void
    {
        $this->seed(1, 'Ada', 'new');
        $this->seed(2, 'Grace', 'won');

        $base = $this->builder();

        $base->where('status', 'new');
        $base->where('status', 'won');

        $this->assertCount(
            2,
            $base->get(),
            'The base must still return every row; the derived builders took their own conditions with them.'
        );
    }

    /**
     * A limit must not be left behind by `first()`.
     *
     * `first()` needs a `LIMIT 1`, and applying it to `$this` would silently cap
     * every later read — the builder would look unlimited and behave otherwise.
     * Two rows are seeded so a leaked limit is visible as a count of 1.
     */
    public function test_first_does_not_leave_a_limit_on_the_builder(): void
    {
        $this->seed(1, 'Ada', 'new');
        $this->seed(2, 'Grace', 'new');

        $builder = $this->builder();

        $this->assertNotNull($builder->first());
        $this->assertCount(2, $builder->get(), 'first() must not cap the builder it was called on.');
    }

    /**
     * Ordering is part of a builder's value, so it must clone like everything else.
     */
    public function test_ordering_does_not_mutate_the_original(): void
    {
        $this->seed(1, 'Ada', 'new');
        $this->seed(2, 'Grace', 'new');

        $base = $this->builder();

        $ascending = $base->orderBy('name', 'asc');
        $descending = $base->orderBy('name', 'desc');

        $this->assertSame(['Ada', 'Grace'], array_column($ascending->get(), 'name'));
        $this->assertSame(['Grace', 'Ada'], array_column($descending->get(), 'name'));
    }

    // ------------------------------------------------------------------ refusals

    /**
     * An operator outside the closed list is refused before SQL exists.
     *
     * The list is closed because an operator is statement *structure*: a caller
     * who can pass any string can pass one that ends the clause and begins
     * another, which is the injection the builder exists to prevent.
     *
     * `LIKE` used to be the example here, and is now supported - see
     * {@see test_like_is_a_supported_operator()}. `REGEXP` stands in its place:
     * a real MySQL operator, and one a builder has no business accepting on a
     * caller's word.
     */
    public function test_an_unsupported_operator_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/not supported by the query builder/');

        $this->builder()->where('status', 'new', 'REGEXP');
    }

    /**
     * `LIKE` is accepted, case-insensitively, and its pattern is bound not quoted.
     *
     * The operator arrives in whichever case the caller wrote it and is
     * normalized before it enters the statement, so `like` and `LIKE` are one
     * operator rather than two a caller has to guess between.
     */
    public function test_like_is_a_supported_operator(): void
    {
        $this->seed(1, 'Ada Lovelace', 'new');
        $this->seed(2, 'Grace Hopper', 'new');

        $rows = $this->builder()->where('name', '%Lovelace%', 'LIKE')->get();

        $this->assertSame(['Ada Lovelace'], array_column($rows, 'name'));
    }

    /**
     * The wildcards are the caller's to place, and the builder adds none.
     *
     * A pattern with no `%` matches exactly, which is what distinguishes `LIKE`
     * from a builder that helpfully wraps every term in wildcards - a difference
     * a caller cannot see from the call site unless a test states it.
     */
    public function test_like_does_not_add_wildcards_of_its_own(): void
    {
        $this->seed(1, 'Ada', 'new');
        $this->seed(2, 'Ada Lovelace', 'new');

        $rows = $this->builder()->where('name', 'Ada', 'LIKE')->get();

        $this->assertSame(['Ada'], array_column($rows, 'name'));
    }

    /**
     * A column name carrying SQL is refused, because a column cannot be bound.
     *
     * This is the case that matters: a value is a parameter and safe by
     * construction, but an identifier is pasted into the statement, so it is the
     * one caller-supplied string that could alter the query's shape.
     */
    public function test_a_column_name_that_is_not_a_bare_identifier_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/is not usable in a query/');

        $this->builder()->where('status` = \'x\' OR 1=1 -- ', 'new');
    }

    /**
     * A direction outside the map is refused rather than interpolated.
     *
     * `ORDER BY` is the other place a caller's word reaches the statement
     * unparameterised, and `name ASC, (SELECT ...)` is a direction string that
     * is also a second sort key.
     */
    public function test_an_unsupported_sort_direction_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/sort direction/');

        $this->builder()->orderBy('name', 'asc, id desc');
    }

    /**
     * A non-positive limit is refused.
     *
     * MySQL rejects a negative `LIMIT` and SQLite reads it as zero, so the two
     * backends would answer the same request differently — which is a silent
     * difference in production rather than a visible error.
     */
    public function test_a_non_positive_limit_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/at least 1/');

        $this->builder()->limit(0);
    }

    /**
     * A negative offset is refused for the same reason as a limit.
     */
    public function test_a_negative_offset_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/cannot be negative/');

        $this->builder()->offset(-1);
    }

    /**
     * A read with no table names the missing call rather than emitting broken SQL.
     */
    public function test_reading_without_a_table_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/until table\(\) has named one/');

        (new WpFluentQueryBuilder($this->db))->get();
    }

    /**
     * An unqualified `DELETE` is refused rather than emptying the table.
     *
     * The most consequential refusal in the class: `DELETE FROM t` is valid SQL
     * and destroys every row, and the mistake behind it — a forgotten `where()`
     * — is invisible in the call site.
     */
    public function test_delete_without_a_condition_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/no conditions/');

        $this->builder()->delete();
    }

    /**
     * An unqualified `UPDATE` is refused for the same reason.
     */
    public function test_update_without_a_condition_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/no conditions/');

        $this->builder()->update(['status' => 'won']);
    }

    /**
     * An `INSERT` with no columns is refused.
     */
    public function test_insert_with_no_values_is_refused(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/with no columns/');

        $this->builder()->insertGetId([]);
    }
}
