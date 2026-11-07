<?php
declare(strict_types=1);

namespace Tests\Unit\Database;

use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\Lifecycle\MigratesOnActivation;
use Framework\Database\Migrator;
use Framework\Database\Schema\Schema;
use Framework\Database\Storage\WpMigrationStorage;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;
use Tests\Support\MigrationWorkspace;
use Tests\Support\RecordingMigration;
use Tests\Support\SchemaMigration;
use Tests\Support\ThrowingMigration;
use Tests\Support\WpStub;

/**
 * Activation, driven directly against a fake database.
 *
 * The activation callback is a one-shot path that no test could reach without a
 * live WordPress install, which is precisely why the logic lives in a class
 * rather than in a closure in the entry file. Everything below exercises the
 * real class.
 */
final class MigratesOnActivationTest extends TestCase
{
    private MigrationWorkspace $workspace;
    private FakeWpdb $db;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->workspace = new MigrationWorkspace();
        $this->db = new FakeWpdb();

        // The migrations here go through the static `Schema::` entry points, as
        // a real migration does, so the seam has to be installed.
        Schema::useAdapter(new WpDatabaseAdapter($this->db));
    }

    protected function tearDown(): void
    {
        Schema::stopPretending();
        Schema::useAdapter(null);

        $this->workspace->cleanup();
    }

    public function test_activation_applies_every_pending_migration(): void
    {
        // `SchemaMigration` always builds `users` — the fixture's table name is
        // fixed and the *identifier* is what the workspace rewrites. So only one
        // migration in this workspace may be schema-backed; the second is a
        // recording migration, which issues no SQL and would otherwise collide
        // on the same table rather than proving a second migration ran.
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $applied = $this->activation()();

        $this->assertCount(2, $applied);
        $this->assertTrue($this->db->tableExists('wp_users'), 'The schema migration built no table.');
        $this->assertCount(2, $this->db->rows('wp_plugin_migrations'));
    }

    /**
     * The property that makes reactivation safe. A second activation must find
     * nothing pending and touch nothing — if it re-ran `up()`, every migration
     * would fail on a duplicate table, or worse, drop data on the way.
     */
    public function test_activation_is_idempotent(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);

        $first = $this->activation()();
        $second = $this->activation()();

        $this->assertCount(1, $first);
        $this->assertSame([], $second, 'A second activation re-ran migrations.');
        $this->assertCount(1, $this->db->rows('wp_plugin_migrations'));
    }

    /**
     * Activation creates what is missing and leaves what exists. This is the
     * case that a `migrate:fresh`-style activation would destroy: a plugin
     * reactivated on a site that has been running with it.
     */
    public function test_activation_leaves_existing_data_alone(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->activation()();

        // Raw SQL rather than `insert()`, to avoid pinning this test to the
        // fake's insert builder — the assertion is about what *activation* does
        // to a row that is already there, and nothing else.
        $this->db->query("INSERT INTO `wp_users` (`email`) VALUES ('someone@example.test')");

        $this->assertCount(1, $this->db->rows('wp_users'), 'Precondition: the fixture row was not written.');

        // A new migration is added while the install already has data, which is
        // what a plugin update followed by a reactivation looks like. It records
        // rather than building a table, because `SchemaMigration`'s table name is
        // fixed: a second schema migration here would collide on `users` rather
        // than model a new table, and the test would be asserting on the
        // fixture's limitation instead of on activation.
        $this->workspace->write('2026_11_05_120000_add_a_column', RecordingMigration::class);
        $applied = $this->activation()();

        // Only the new migration ran — the already-applied one was not replayed
        // and, in particular, nothing was dropped and rebuilt.
        $this->assertSame(['2026_11_05_120000_add_a_column'], $applied);
        $this->assertTrue($this->db->tableExists('wp_users'));
        $this->assertCount(1, $this->db->rows('wp_users'), 'Activation destroyed existing rows.');
    }

    /**
     * A failing migration must not escape. `up()` throwing out of an activation
     * request is a fatal WordPress reports as "the plugin could not be
     * activated" with no cause attached — the operator sees a white screen and
     * no plugin, and nothing anywhere says which migration failed.
     */
    public function test_a_failing_migration_is_reported_rather_than_thrown(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', ThrowingMigration::class);

        $reported = [];
        $activation = new MigratesOnActivation($this->migrator(), function (\Throwable $e) use (&$reported): void {
            $reported[] = $e;
        });

        $applied = $activation();

        $this->assertCount(1, $reported, 'The failure was swallowed without being reported.');
        $this->assertStringContainsString(ThrowingMigration::MESSAGE, $reported[0]->getMessage());

        // The migration that succeeded before the failure is still applied and
        // still recorded — the truthful half-state the migrator is built to
        // leave behind, and the one `migrate` resumes from.
        $this->assertSame(['2026_11_05_100000_create_users_table'], $applied);
        $this->assertCount(1, $this->db->rows('wp_plugin_migrations'));
    }

    /**
     * With no reporter, the failure goes to the error log rather than nowhere.
     *
     * Asserted on the return value rather than by capturing the log: the point
     * of the default reporter is that it never throws and never changes the
     * outcome, which is exactly what a return value shows.
     */
    public function test_a_failure_with_no_reporter_at_all_still_returns_cleanly(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', ThrowingMigration::class);

        $applied = $this->activation()();

        $this->assertSame([], $applied);
    }

    /**
     * A missing migration directory is a migrator failure, not a migration one,
     * and it must be reported rather than fataling during activation.
     */
    public function test_a_missing_directory_is_reported_rather_than_thrown(): void
    {
        $migrator = new Migrator(
            $this->workspace->path() . '/absent',
            new WpDatabaseAdapter($this->db),
            new WpMigrationStorage($this->db),
        );

        $reported = [];
        $activation = new MigratesOnActivation($migrator, function (\Throwable $e) use (&$reported): void {
            $reported[] = $e;
        });

        $this->assertSame([], $activation());
        $this->assertCount(1, $reported);
    }

    private function migrator(): Migrator
    {
        return new Migrator(
            $this->workspace->path(),
            new WpDatabaseAdapter($this->db),
            new WpMigrationStorage($this->db),
        );
    }

    private function activation(): MigratesOnActivation
    {
        return new MigratesOnActivation($this->migrator());
    }
}
