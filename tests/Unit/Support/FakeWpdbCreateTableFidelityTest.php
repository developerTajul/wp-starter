<?php
declare(strict_types=1);

namespace Tests\Unit\Support;

use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;

/**
 * Whether `FakeWpdb` reports a created table as it really is.
 *
 * ## Why this test is here, and why it comes first
 *
 * Every schema assertion in this suite is read back out of the fake: a migration
 * test asks it which columns exist, a dumper test asks it what they dump as, and
 * the round-trip test asks it whether a replayed schema matches the original.
 * All of that is only worth anything if the fake's catalogue is an honest
 * observation of the `CREATE TABLE` that was run against it — and it was not.
 *
 * The parser scanned for four type keywords (`bigint|varchar|int|tinyint`) and
 * nothing else, so a table containing a `decimal`, a `timestamp`, a `text` or a
 * `date` came back from `createTable()` with those columns **missing entirely**.
 * It then stamped every column it *did* find as `varchar(191) NULL`, discarding
 * the type, the nullability and the default from the statement it had just been
 * given. A test asserting on such a table was asserting on a schema the database
 * was never asked to create.
 *
 * ## Why this asserts against the statement, not against a literal
 *
 * The obvious shape here is `assertSame('timestamp', $db->columnMeta(...))` —
 * and it is the wrong one, because it hardcodes this test to today's `timestamp`
 * spelling and tells a reader nothing about *why*. Instead the schema is declared
 * through `Schema::create()`, the exact `CREATE TABLE` that produced is captured,
 * and the fake's catalogue is then compared against what that statement says. The
 * statement is the ground truth; the fake either agrees with it or it does not,
 * and the failure message quotes both halves.
 *
 * @see FakeWpdb::createTable()   The parser under test.
 * @see \Framework\Database\Adapters\WpDatabaseAdapter::createTable()  What emits
 *      the statement being compared against.
 */
final class FakeWpdbCreateTableFidelityTest extends TestCase
{
    /**
     * The shape of every column the round-trip schema declares.
     *
     * Deliberately spelled out here rather than shared with the round-trip test,
     * so that a change to one is a deliberate change to both rather than a silent
     * agreement between two things that should be checked independently.
     *
     * @var array<string, string> Column name => the type fragment MySQL reports.
     */
    private const EXPECTED_TYPES = [
        'id' => 'bigint(20) unsigned',
        'title' => 'varchar(191)',
        'status' => 'varchar(191)',
        'is_archived' => 'tinyint(1)',
        'amount' => 'decimal(10,2)',
        'created_at' => 'timestamp',
    ];

    private FakeWpdb $db;

    private ?\wpdb $restoreGlobal = null;

    protected function setUp(): void
    {
        $this->db = new FakeWpdb();

        Schema::useAdapter(new WpDatabaseAdapter($this->db));

        $previous = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $this->db;
        $this->restoreGlobal = $previous;
    }

    protected function tearDown(): void
    {
        Schema::stopPretending();
        Schema::useAdapter(null);

        if ($this->restoreGlobal instanceof \wpdb) {
            $GLOBALS['wpdb'] = $this->restoreGlobal;
        } else {
            unset($GLOBALS['wpdb']);
        }

        parent::tearDown();
    }

    /**
     * **The defect.** Every column the statement declares is a column the fake
     * knows about.
     *
     * A dropped column is the worst kind of fake, because it is silent: a test
     * asserting "the dumper emits a `decimal` call" reads a catalogue that never
     * had a decimal in it, sees no decimal, and either passes for the wrong reason
     * or reports a dumper bug that is really a fake bug.
     */
    public function test_every_column_in_the_create_statement_is_recorded(): void
    {
        $this->declare();

        $declared = $this->columnsInCreateStatement();
        $recorded = $this->db->columns('wp_fidelity');

        sort($declared);
        sort($recorded);

        $this->assertSame(
            $declared,
            $recorded,
            "The fake's catalogue disagrees with the CREATE TABLE it was given.\n\n"
            . 'declared: ' . implode(', ', $declared) . "\n"
            . 'recorded: ' . implode(', ', $recorded) . "\n\n"
            . 'A column present in one and not the other is a column every schema assertion in this '
            . 'suite is silently wrong about.'
        );
    }

    /**
     * The type the statement declares is the type the catalogue reports.
     *
     * This is what `columnDetails()` hands the dumper, so a fake that reports
     * `varchar(191)` for a `timestamp` makes the dumper's *correct* branch — the
     * one that emits `timestamp('created_at')` — unreachable in every test.
     */
    public function test_the_recorded_type_matches_the_statement(): void
    {
        $this->declare();

        foreach (self::EXPECTED_TYPES as $column => $expected) {
            $this->assertSame(
                $expected,
                $this->db->columnMeta('wp_fidelity', $column)['Type'],
                sprintf(
                    'The fake reports the wrong type for [%s]. `SchemaDumper` selects its builder call '
                    . 'from this string, so a wrong type here makes the dumper emit a call for a column '
                    . 'that does not exist — and the test asserting on it fails against correct code.',
                    $column
                )
            );
        }
    }

    /**
     * `NOT NULL` survives into the catalogue.
     *
     * Nullability is half of what a dump has to reproduce. A fake that reports
     * every column as `YES` cannot distinguish a correct `->nullable()` from a
     * dropped one, which is exactly the bug the round-trip test exists to catch.
     */
    public function test_nullability_matches_the_statement(): void
    {
        $this->declare();

        // `title` is declared nullable; `status` is not.
        $this->assertSame('YES', $this->db->columnMeta('wp_fidelity', 'title')['Null']);
        $this->assertSame('NO', $this->db->columnMeta('wp_fidelity', 'status')['Null']);
    }

    /**
     * A scalar `DEFAULT` survives into the catalogue.
     *
     * The dumper emits `->default('draft')` only if it can see `draft`, so a
     * fake that drops defaults makes the dumper look like it is losing them.
     */
    public function test_a_scalar_default_matches_the_statement(): void
    {
        $this->declare();

        $this->assertSame('draft', $this->db->columnMeta('wp_fidelity', 'status')['Default']);
    }

    /**
     * `AUTO_INCREMENT` is recognised, because `id()` is selected from it.
     *
     * `SchemaDumper::builderCall()` chooses between `id()` and a plain integer
     * call on this flag alone. A fake that never sets it dumps every primary key
     * as `bigInteger('id')` — a column that is not a key, in a table that then
     * has no primary key at all.
     */
    public function test_auto_increment_is_recognised(): void
    {
        $this->declare();

        $this->assertStringContainsString('auto_increment', $this->db->columnMeta('wp_fidelity', 'id')['Extra']);
    }

    /**
     * The `KEY` lines in the statement become indexes the catalogue can report.
     *
     * The dumper reads these through `SHOW INDEX`, so a `KEY` the fake did not
     * record is an index the dump silently omits — and the replayed schema is
     * then missing a key, which no `php -l` would ever catch.
     */
    public function test_keys_declared_in_the_statement_are_recorded(): void
    {
        $this->declare();

        $this->assertSame(
            ['slug', 'pipeline_id_stage_id'],
            array_keys($this->db->indexes('wp_fidelity'))
        );
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Declare the schema under test through the ordinary builder path.
     */
    private function declare(): void
    {
        Schema::create('fidelity', function ($table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->string('status')->default('draft');
            $table->boolean('is_archived')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->string('slug')->nullable()->index();
            $table->integer('pipeline_id')->nullable();
            $table->integer('stage_id')->nullable();
            $table->index(['pipeline_id', 'stage_id']);
        });
    }

    /**
     * The column names the `CREATE TABLE` actually declared.
     *
     * Parsed out of the recorded statement rather than restated, so this test
     * cannot drift from what the adapter emits: if the adapter stops emitting a
     * column, the expectation moves with it and the fake is judged against the
     * new statement.
     *
     * @return list<string>
     */
    private function columnsInCreateStatement(): array
    {
        $statement = $this->createStatement();

        $body = (string) preg_replace('/^.*?\((.*)\)[^()]*$/s', '$1', $statement);

        $columns = [];

        foreach (explode("\n", $body) as $line) {
            $line = trim(rtrim(trim($line), ','));

            if ($line === '' || preg_match('/^(primary|unique|key|constraint|index|foreign)\b/i', $line) === 1) {
                continue;
            }

            if (preg_match('/^`([a-z0-9_]+)`/i', $line, $m) === 1) {
                $columns[] = $m[1];
            }
        }

        return $columns;
    }

    /**
     * The one `CREATE TABLE` the declaration issued.
     */
    private function createStatement(): string
    {
        foreach ($this->db->queries as $query) {
            if (preg_match('/^\s*create\s+table\s+`?wp_fidelity`?/i', $query) === 1) {
                return $query;
            }
        }

        $this->fail('The declaration issued no CREATE TABLE; the adapter or the fake dropped it.');
    }
}
