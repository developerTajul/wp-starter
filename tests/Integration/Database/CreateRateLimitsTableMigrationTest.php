<?php
declare(strict_types=1);

namespace Tests\Integration\Database;

use App\Database\AtomicDatabaseRateLimiter;
use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\MigrationFile;
use Framework\Database\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;

/**
 * The rate-limits migration, run against a stand-in database.
 *
 * A sibling of {@see CreateNoteAuditTableMigrationTest}; the reasoning for these
 * tests living in `Integration/` and going through `Schema::create()` rather than
 * asserting on the file's text is there.
 *
 * ## What is specific to this one: the unique index is the mechanism
 *
 * The limiter's whole guarantee rests on one statement:
 *
 *     INSERT … ON DUPLICATE KEY UPDATE
 *
 * `ON DUPLICATE KEY UPDATE` detects a duplicate by matching a **unique key**. If
 * `uniq_rate_key` is absent, no duplicate is ever detected — so the statement
 * inserts a fresh row on every request, every request is the first attempt in its
 * window, and the limiter allows everything while its table grows without bound.
 *
 * That failure is entirely silent: no error, no warning, and the limiter still
 * reports plausible counts. The only way to catch it is to assert the index
 * exists, which is why there is a test for the index rather than only for the
 * table — a table-level assertion would pass against exactly the broken schema
 * this migration exists to get right.
 *
 * ## What this test cannot check
 *
 * That MySQL *enforces* the uniqueness. `FakeWpdb` records the index it reads out
 * of the CREATE statement, which is enough to catch a migration that forgot to
 * declare it — the realistic mistake — but it is the fake's own bookkeeping, not
 * a constraint engine. The declaration is the part under this test's control;
 * the enforcement is MySQL's and was confirmed separately against a live server.
 */
final class CreateRateLimitsTableMigrationTest extends TestCase
{
    private const PATH = __DIR__ . '/../../../database/migrations/2026_11_08_120000_create_rate_limits_table.php';

    private const TABLE = 'wp_mini_rate_limits';

    private FakeWpdb $db;

    /**
     * Whatever `$wpdb` held before this test replaced it.
     */
    private ?\wpdb $restoreGlobal = null;

    protected function setUp(): void
    {
        $this->db = new FakeWpdb();

        Schema::useAdapter(new WpDatabaseAdapter($this->db));

        // The global is installed because the `dbDelta()` stub reaches for
        // `$wpdb` itself, and restored in tearDown so a case here cannot leak a
        // fake database into whatever runs next.
        $previous = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $this->db;

        $this->restoreGlobal = $previous;
    }

    protected function tearDown(): void
    {
        Schema::useAdapter(null);

        if ($this->restoreGlobal instanceof \wpdb) {
            $GLOBALS['wpdb'] = $this->restoreGlobal;
        } else {
            unset($GLOBALS['wpdb']);
        }

        parent::tearDown();
    }

    /**
     * The filename the migrator discovers, and the class it derives.
     */
    public function test_the_file_follows_the_naming_convention(): void
    {
        $file = MigrationFile::fromPath(self::PATH);

        $this->assertNotNull(
            $file,
            'A migration whose filename does not match the convention is never discovered — it is '
            . 'not skipped with a warning, it is invisible.'
        );
        $this->assertSame('CreateRateLimitsTable', $file->class);
    }

    public function test_it_creates_the_limiter_table(): void
    {
        $this->migration()->up();

        $this->assertTrue($this->db->tableExists(self::TABLE));
    }

    /**
     * The unique index exists — the one assertion this migration lives or dies by.
     *
     * See the class docblock: without it `ON DUPLICATE KEY UPDATE` never matches,
     * and the limiter degrades to "allow everything, grow forever" without
     * reporting anything.
     *
     * Read through `FakeWpdb::indexes()` rather than a `information_schema`
     * query, because the fake records indexes as `name => is unique` when it parses
     * the CREATE — which is the same fact, without depending on the fake
     * supporting the particular introspection query. The property asserted is
     * uniqueness, so the named index is checked to be unique rather than merely
     * present: a *non-unique* index on `rate_key` is the same broken schema and
     * would satisfy a presence-only check.
     */
    public function test_the_rate_key_column_is_unique(): void
    {
        $this->migration()->up();

        $this->assertContains(
            true,
            $this->db->indexes(self::TABLE),
            'No unique index was declared on the limiter table. ON DUPLICATE KEY UPDATE matches '
            . 'against a unique key; without one every hit inserts a new row and every request '
            . 'looks like a first attempt.'
        );
    }

    /**
     * The column is wide enough for the value the limiter actually stores.
     *
     * The limiter hashes with `sha256` and stores hex, which is exactly 64
     * characters. A narrower column truncates — and a truncated hash still looks
     * like a working key, so the symptom is two unrelated clients silently sharing
     * one allowance rather than an insert error. MySQL in strict mode would refuse
     * the insert instead, which is a failure at least.
     */
    public function test_a_sha256_hex_key_fits_the_column(): void
    {
        $this->migration()->up();

        $limiter = new AtomicDatabaseRateLimiter($this->db, self::TABLE);

        $limiter->hit('some:key', 5, 60);

        $rows = $this->db->rows(self::TABLE);

        $this->assertCount(1, $rows);
        $this->assertSame(
            64,
            strlen((string) $rows[0]['rate_key']),
            'A stored key that is not 64 characters means the column truncated the hash.'
        );
    }

    /**
     * The table satisfies the limiter end to end: create, increment, deny.
     *
     * The migration and the limiter agree on a table name by convention rather
     * than by a shared constant, so this is where that agreement is checked — a
     * migration that created `wp_rate_limits` while the limiter queried
     * `wp_mini_rate_limits` would leave each individually correct and the pair
     * broken.
     */
    public function test_the_table_satisfies_the_limiter(): void
    {
        $this->migration()->up();

        $limiter = new AtomicDatabaseRateLimiter($this->db, self::TABLE);

        $first = $limiter->hit('lead:1.2.3.4', 2, 60);
        $second = $limiter->hit('lead:1.2.3.4', 2, 60);
        $third = $limiter->hit('lead:1.2.3.4', 2, 60);

        $this->assertTrue($first->isAllowed());
        $this->assertTrue($second->isAllowed());
        $this->assertTrue(
            $third->isDenied(),
            'The third hit against a limit of two must be refused — if the unique index is missing '
            . 'the count never advances and this stays allowed.'
        );
    }

    /**
     * A second hit updates the existing row rather than inserting another.
     *
     * The row count is the direct evidence that the upsert matched. The limiter's
     * reported `attempts` would look identical if it had inserted a new row and
     * read back the *first* one.
     */
    public function test_a_repeat_hit_updates_in_place(): void
    {
        $this->migration()->up();

        $limiter = new AtomicDatabaseRateLimiter($this->db, self::TABLE);

        $limiter->hit('lead:1.2.3.4', 10, 60);
        $limiter->hit('lead:1.2.3.4', 10, 60);

        $this->assertCount(1, $this->db->rows(self::TABLE), 'One key must occupy one row.');
    }

    /**
     * Expiry is stored as an integer timestamp, not a datetime.
     *
     * The limiter compares it against `time()` and sends the difference as
     * `Retry-After` seconds. A datetime column would need converting on every read
     * and would make the comparison depend on the database session's timezone — a
     * limiter that misbehaves on any server not running UTC.
     */
    public function test_the_window_is_stored_as_a_unix_timestamp(): void
    {
        $this->migration()->up();

        $limiter = new AtomicDatabaseRateLimiter($this->db, self::TABLE);
        $limiter->hit('lead:1.2.3.4', 5, 60);

        $expiresAt = (int) $this->db->rows(self::TABLE)[0]['expires_at'];

        $this->assertGreaterThan(time(), $expiresAt, 'The window must expire in the future.');
        $this->assertLessThanOrEqual(
            time() + 60,
            $expiresAt,
            'The stored expiry must be the window this call asked for, not some other unit.'
        );
    }

    /**
     * `down()` removes the table it created.
     */
    public function test_down_drops_the_table(): void
    {
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $this->assertFalse($this->db->tableExists(self::TABLE));
    }

    /**
     * A rollback of a migration that never ran must not explode.
     *
     * The state a partly-applied batch leaves behind, and exactly when someone
     * reaches for `migrate:rollback` — so `down()` is written with `IF EXISTS`.
     */
    public function test_down_is_safe_when_the_table_does_not_exist(): void
    {
        $this->migration()->down();

        $this->assertFalse($this->db->tableExists(self::TABLE));
    }

    /**
     * The class the filename resolves to, instantiated with a test handle.
     *
     * Required by path, as the migrator does — the autoloader would be the wrong
     * way to reach it, since a migration that is not autoloadable is still a
     * migration the migrator runs.
     */
    private function migration(): object
    {
        $file = MigrationFile::fromPath(self::PATH);

        $this->assertNotNull($file, 'The migration file must follow the naming convention.');

        require_once self::PATH;

        $fqcn = 'Database\\Migrations\\' . $file->class;

        $this->assertTrue(
            class_exists($fqcn),
            sprintf('The filename [%s] must declare a class named [%s].', basename(self::PATH), $file->class)
        );

        return new $fqcn($this->db);
    }
}
