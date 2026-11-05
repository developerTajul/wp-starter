<?php
declare(strict_types=1);

namespace Tests\Unit\Database;

use Framework\Config\Config;
use Framework\Database\Commands\MigrateCommand;
use Framework\Database\Commands\MigrateRollbackCommand;
use Framework\Database\Commands\MigrateStatusCommand;
use Framework\Database\Commands\MakeMigrationCommand;
use Framework\Database\Migrator;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;
use Tests\Support\MigrationWorkspace;
use Tests\Support\RecordingMigration;
use Tests\Support\ThrowingMigration;
use Tests\Support\WpStub;

/**
 * The console commands, driven directly.
 *
 * The commands are WP-CLI-shaped but not WP-CLI-dependent: they take the
 * migrator by constructor injection and report through the base `Command`'s
 * helpers, which are no-ops when `\WP_CLI` is absent. So the suite can run them
 * for real and read the messages off the subclasses below.
 *
 * That the real command classes are exercised — rather than reimplementations —
 * is the point: a command whose output said "migrated" while doing nothing would
 * fail here.
 */
final class MigrationCommandsTest extends TestCase
{
    private MigrationWorkspace $workspace;
    private FakeWpdb $db;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->workspace = new MigrationWorkspace();
        $this->db = new FakeWpdb();
    }

    protected function tearDown(): void
    {
        $this->workspace->cleanup();
    }

    public function test_migrate_runs_pending_migrations_and_reports_the_count(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $command = $this->migrateCommand();
        $command->handle([], []);

        $this->assertStringContainsString('2 migration(s)', implode("\n", $command->messages));
        $this->assertCount(2, $this->db->rows('wp_plugin_migrations'));
    }

    /**
     * A second run must say so rather than reporting a successful migration of
     * zero things, which reads as "something happened".
     */
    public function test_migrate_reports_when_there_is_nothing_to_do(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $migrator = $this->migrator();
        $migrator->runPending();

        $command = $this->migrateCommand();
        $command->handle([], []);

        $this->assertStringContainsString('Nothing to migrate', implode("\n", $command->messages));
    }

    /**
     * A failing migration must be reported without a stack trace escaping to the
     * terminal, and the message must name the migration.
     */
    public function test_migrate_reports_a_failure_with_the_cause(): void
    {
        $this->workspace->write('2026_11_05_120000_create_leads_table', ThrowingMigration::class);

        $command = $this->migrateCommand();
        $command->handle([], []);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString('2026_11_05_120000_create_leads_table', $output);
        $this->assertStringContainsString('Caused by', $output);
        $this->assertStringContainsString(ThrowingMigration::MESSAGE, $output);
    }

    public function test_migrate_reports_a_missing_directory_rather_than_failing(): void
    {
        $migrator = new Migrator($this->workspace->path() . '/absent', $this->db);

        $command = new TestableMigrateCommand($migrator);
        $command->handle([], []);

        $this->assertStringContainsString('does not exist', implode("\n", $command->messages));
    }

    public function test_rollback_reverses_the_last_batch(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);

        $this->migrator()->runPending();

        $command = $this->rollbackCommand();
        $command->handle([], []);

        $this->assertStringContainsString('Rolled back 1 migration(s)', implode("\n", $command->messages));
        $this->assertSame([], $this->db->rows('wp_plugin_migrations'));
    }

    public function test_rollback_with_nothing_applied_reports_so(): void
    {
        $command = $this->rollbackCommand();
        $command->handle([], []);

        $this->assertStringContainsString('Nothing to roll back', implode("\n", $command->messages));
    }

    public function test_rollback_accepts_an_explicit_batch(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $command = $this->rollbackCommand();
        $command->handle([], ['batch' => '1']);

        $this->assertSame(
            ['2026_11_05_120000_create_leads_table'],
            array_column($this->db->rows('wp_plugin_migrations'), 'migration')
        );
    }

    /**
     * `--batch=all` would cast to 0 and silently match a batch that cannot exist,
     * reporting "nothing to roll back" for a command the operator believes
     * succeeded. It has to be rejected instead.
     */
    public function test_rollback_rejects_an_invalid_batch_argument(): void
    {
        $command = $this->rollbackCommand();
        $command->handle([], ['batch' => 'all']);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString('not a valid batch number', $output);
        $this->assertStringNotContainsString('Nothing to roll back', $output);
    }

    public function test_status_lists_every_migration_with_its_batch(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $this->workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

        $command = $this->statusCommand();
        $command->handle([], []);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString('2026_11_05_100000_create_users_table', $output);
        $this->assertStringContainsString('2026_11_05_120000_create_leads_table', $output);
        $this->assertStringContainsString('CreateUsersTable', $output);
        $this->assertStringContainsString('1 pending', $output);
    }

    public function test_status_reports_migrations_whose_files_are_missing(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
        $this->migrator()->runPending();

        $this->workspace->delete('2026_11_05_100000_create_users_table');

        $command = $this->statusCommand();
        $command->handle([], []);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString('recorded as ran but their files are missing', $output);
    }

    public function test_status_handles_an_empty_directory(): void
    {
        $command = $this->statusCommand();
        $command->handle([], []);

        $this->assertStringContainsString('No migrations found', implode("\n", $command->messages));
    }

    // ------------------------------------------------------------------ wiring

    /**
     * The commands must be resolvable from the container with the migrator
     * injected — that is how the console kernel builds them.
     */
    public function test_the_commands_resolve_through_the_container(): void
    {
        $container = $this->container();

        $this->assertInstanceOf(MigrateCommand::class, $container->resolve(MigrateCommand::class));
        $this->assertInstanceOf(MigrateRollbackCommand::class, $container->resolve(MigrateRollbackCommand::class));
        $this->assertInstanceOf(MigrateStatusCommand::class, $container->resolve(MigrateStatusCommand::class));
    }

    public function test_the_project_registers_the_migration_commands(): void
    {
        $commands = (new \App\Console\Kernel(new \DevLinkStudios\WPServiceContainer\Container()))->getCommands();

        $this->assertContains(MigrateCommand::class, $commands);
        $this->assertContains(MigrateRollbackCommand::class, $commands);
        $this->assertContains(MigrateStatusCommand::class, $commands);
        $this->assertContains(MakeMigrationCommand::class, $commands);
    }

    public function test_command_names_are_prefixed(): void
    {
        $prefix = config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX);

        $this->assertSame("{$prefix} migrate", $this->migrateCommand()->getName());
        $this->assertSame("{$prefix} migrate:rollback", $this->rollbackCommand()->getName());
        $this->assertSame("{$prefix} migrate:status", $this->statusCommand()->getName());
        $this->assertSame("{$prefix} make:migration", (new MakeMigrationCommand())->getName());
    }

    public function test_every_migration_command_declares_a_description(): void
    {
        foreach ([
            new MakeMigrationCommand(),
            $this->migrateCommand(),
            $this->rollbackCommand(),
            $this->statusCommand(),
        ] as $command) {
            $this->assertNotSame('', $command->getDescription());
        }
    }

    // ----------------------------------------------------------------- helpers

    /**
     * A container with the same self-bindings the real one has, plus the
     * migration provider registered.
     */
    private function container(): \DevLinkStudios\WPServiceContainer\Container
    {
        $container = new \DevLinkStudios\WPServiceContainer\Container();
        $container->instance(
            \DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface::class,
            $container
        );
        $container->instance(\DevLinkStudios\WPServiceContainer\Container::class, $container);

        // The provider binds a default Migrator (real base_path, real $wpdb);
        // rebinding after it points the commands at this test's fixtures, which
        // is also how a project with a different layout would override it.
        (new \Framework\Database\MigrationServiceProvider($container))->register();
        $container->instance(Migrator::class, $this->migrator());

        return $container;
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->workspace->path(), $this->db);
    }

    private function migrateCommand(): TestableMigrateCommand
    {
        return new TestableMigrateCommand($this->migrator());
    }

    private function rollbackCommand(): TestableRollbackCommand
    {
        return new TestableRollbackCommand($this->migrator());
    }

    private function statusCommand(): TestableStatusCommand
    {
        return new TestableStatusCommand($this->migrator());
    }
}

/**
 * The real commands with their output captured.
 *
 * Only the reporting helpers are overridden — the behaviour under test is the
 * command's own `handle()`, which is inherited unchanged.
 */
final class TestableMigrateCommand extends MigrateCommand
{
    /** @var list<string> */
    public array $messages = [];

    protected function success(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function error(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function info(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function warning(string $message): void
    {
        $this->messages[] = $message;
    }
}

final class TestableRollbackCommand extends MigrateRollbackCommand
{
    /** @var list<string> */
    public array $messages = [];

    protected function success(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function error(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function info(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function warning(string $message): void
    {
        $this->messages[] = $message;
    }
}

final class TestableStatusCommand extends MigrateStatusCommand
{
    /** @var list<string> */
    public array $messages = [];

    protected function success(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function error(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function info(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function warning(string $message): void
    {
        $this->messages[] = $message;
    }
}
