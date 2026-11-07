<?php
declare(strict_types=1);

namespace Tests\Unit\Database;

use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\Schema\Blueprint;
use Framework\Database\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;

/**
 * Table-name prefixing, and the foreign keys that depend on it.
 *
 * Both are regressions this file exists to catch, and neither had a test before
 * it. The adapter used to carry a whitelist of "core" tables it left unprefixed,
 * on the belief that WordPress does not prefix its own — it does, always, with
 * whatever `$table_prefix` is set to. So on a site configured `leadbridge_`,
 * `constrained('users')` compiled to `REFERENCES users`, which is a table that
 * does not exist, and the migration died at `CREATE TABLE`. Nothing in the suite
 * could see it: no test asked `tableName()` anything, and every test that ran a
 * migration ran it against a fake whose prefix happened to hide the difference.
 *
 * The prefix is `leadbridge_` rather than the fake's default `wp_` throughout,
 * deliberately. A prefix of `wp_` is the one value where the buggy whitelist and
 * the correct implementation agree, so a test written against it would have
 * passed either way.
 */
final class WpDatabaseAdapterTest extends TestCase
{
    private FakeWpdb $db;

    private WpDatabaseAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new FakeWpdb();
        $this->db->prefix = 'leadbridge_';
        $this->adapter = new WpDatabaseAdapter($this->db);

        Schema::useAdapter($this->adapter);
    }

    /**
     * `Schema::` holds its adapter statically for the life of the process, so it
     * has to be handed back — otherwise the next test class to call `Schema::`
     * runs against this one's fake.
     */
    protected function tearDown(): void
    {
        Schema::useAdapter(null);

        parent::tearDown();
    }

    public function test_table_name_prefixes_every_table_including_wordpress_core(): void
    {
        $this->assertSame('leadbridge_users', $this->adapter->tableName('users'));
        $this->assertSame('leadbridge_posts', $this->adapter->tableName('posts'));
    }

    public function test_table_name_leaves_an_already_qualified_name_alone(): void
    {
        $this->assertSame('leadbridge_leads', $this->adapter->tableName('leadbridge_leads'));
    }

    /**
     * An empty prefix is a real configuration, and `str_starts_with($name, '')`
     * is true for every name — so a guard written without checking for it first
     * reports every table as already qualified and prefixes nothing.
     */
    public function test_table_name_prefixes_nothing_when_the_prefix_is_empty(): void
    {
        $this->db->prefix = '';

        $this->assertSame('users', $this->adapter->tableName('users'));
    }

    /**
     * The FK path end to end: a constraint must leave the CREATE (dbDelta cannot
     * parse one without inventing a column named `CONSTRAINT`) and arrive as a
     * separate `ALTER TABLE`, naming the *prefixed* table it points at.
     */
    public function test_a_foreign_key_is_added_by_alter_and_points_at_the_prefixed_table(): void
    {
        Schema::create('deal_stages', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
        });

        $sql = implode("\n", $this->db->queries);

        $this->assertStringContainsString(
            'ALTER TABLE `leadbridge_deal_stages` ADD CONSTRAINT '
            . '`leadbridge_deal_stages_pipeline_id_foreign` FOREIGN KEY (`pipeline_id`) '
            . 'REFERENCES `leadbridge_users` (`id`) ON DELETE CASCADE',
            $sql
        );
    }

    /**
     * A re-run is the case that made the old shape fail: dbDelta saw the
     * `CONSTRAINT` line as a column, diffed it against the live table, and
     * emitted `ALTER TABLE ... ADD COLUMN CONSTRAINT ...` — valid on the first
     * run and a syntax error on every one after it.
     */
    public function test_running_the_same_create_twice_adds_no_second_constraint(): void
    {
        $create = static function (): void {
            Schema::create('deal_stages', static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('pipeline_id')->constrained('users')->cascadeOnDelete();
                $table->string('name');
            });
        };

        $create();

        $this->db->resetQueries();
        $create();

        $repeated = array_filter(
            $this->db->queries,
            static fn (string $query): bool => stripos($query, 'ADD CONSTRAINT') !== false
        );

        $this->assertSame([], array_values($repeated));
        $this->assertSame(
            ['leadbridge_deal_stages_pipeline_id_foreign'],
            $this->db->constraints('leadbridge_deal_stages')
        );
    }

    /**
     * The CREATE itself must carry no constraint, or dbDelta misparses it — the
     * bug is invisible in the statement list and only shows up on the second run.
     */
    public function test_the_create_statement_carries_no_constraint_clause(): void
    {
        Schema::create('deal_stages', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pipeline_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
        });

        $create = array_values(array_filter(
            $this->db->queries,
            static fn (string $query): bool => stripos($query, 'CREATE TABLE') !== false
        ));

        $this->assertStringNotContainsString('CONSTRAINT', implode("\n", $create));
    }
}
