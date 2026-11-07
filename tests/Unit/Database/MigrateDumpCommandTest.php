<?php
declare(strict_types=1);

namespace Tests\Unit\Database;

use Framework\Config\Config;
use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\Commands\MigrateDumpCommand;
use Framework\Database\Schema\SchemaDumper;
use Framework\Database\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;
use Tests\Support\MigrationWorkspace;
use Tests\Support\WpStub;

/**
 * `migrate:dump` — the console front end over {@see SchemaDumper}.
 *
 * The command's own job is narrow and worth testing separately from the dumper:
 * it discovers which tables are the project's, decides where the file goes, and
 * reports. Everything about *what* the dump says belongs to the dumper, and is
 * covered in {@see SchemaDumperTest}.
 */
final class MigrateDumpCommandTest extends TestCase
{
    private MigrationWorkspace $workspace;
    private FakeWpdb $db;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->workspace = new MigrationWorkspace();
        $this->db = new FakeWpdb();

        Schema::useAdapter(new WpDatabaseAdapter($this->db));
    }

    protected function tearDown(): void
    {
        Schema::stopPretending();
        Schema::useAdapter(null);

        $this->workspace->cleanup();
    }

    /**
     * `--output` writes the dump to the given file.
     *
     * A path flag rather than always writing into `database/migrations/`, because
     * the dump is a snapshot and the common use is to *look* at it — a command
     * that wrote into the migrations directory by default would leave an
     * unrunnable snapshot in every developer's tree the first time they ran it.
     */
    public function test_it_writes_the_dump_to_the_given_path(): void
    {
        $this->db->createTable('wp_notes', ['id', 'title']);
        $this->db->setColumnMeta('wp_notes', 'id', ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Extra' => 'auto_increment']);
        $this->db->setColumnMeta('wp_notes', 'title', ['Type' => 'varchar(191)', 'Null' => 'NO']);

        $target = $this->workspace->path() . '/dump.php';

        $command = $this->command();
        $command->handle([], ['output' => $target, 'table' => ['notes']]);

        $this->assertFileExists($target);
        $this->assertStringContainsString("Schema::create('notes'", (string) file_get_contents($target));
    }

    /**
     * The written file is valid PHP.
     *
     * The command's whole product is a source file, and the check is cheap. A
     * dumper bug that produced an unbalanced brace would otherwise be found by
     * the developer at `migrate` time, with an error about the migration rather
     * than about the tool that wrote it.
     */
    public function test_the_written_file_is_valid_php(): void
    {
        $this->db->createTable('wp_notes', ['id']);
        $this->db->setColumnMeta('wp_notes', 'id', ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Extra' => 'auto_increment']);

        $target = $this->workspace->path() . '/dump.php';

        $this->command()->handle([], ['output' => $target, 'table' => ['notes']]);

        $output = [];
        $status = 0;
        exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($target)), $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
    }

    /**
     * `--table` may be repeated, and each names one table.
     *
     * A comma-separated list would be the other obvious spelling and is
     * deliberately not used: a table name cannot contain a comma, but a caller
     * copying names out of a database listing has no reason to expect one
     * argument to be parsed, and a repeated flag is unambiguous.
     */
    public function test_table_may_be_repeated(): void
    {
        $this->db->createTable('wp_notes', ['id']);
        $this->db->createTable('wp_leads', ['id']);

        $target = $this->workspace->path() . '/dump.php';

        $this->command()->handle([], ['output' => $target, 'table' => ['notes', 'leads']]);

        $contents = (string) file_get_contents($target);

        $this->assertStringContainsString("Schema::create('notes'", $contents);
        $this->assertStringContainsString("Schema::create('leads'", $contents);
    }

    /**
     * With no `--table`, the project's own tables are discovered.
     *
     * "The project's own" means every table carrying the database prefix, minus
     * WordPress's core tables and the framework's tracking table — the same set a
     * developer would list by hand, which is what makes the default useful rather
     * than a footgun that dumps forty core tables.
     *
     * Asserted on the selected names as well as on the file, for the reason given
     * on the unprefixed-table test below: reading `wp_options` and the tracking
     * table out of the result is a decision made here, and the file alone cannot
     * show whether it was made here or downstream by a refusal.
     */
    public function test_without_a_table_it_discover_the_projects_own_tables(): void
    {
        $this->db->createTable('wp_notes', ['id']);
        $this->db->createTable('wp_options', ['id']);
        $this->db->createTable('wp_plugin_migrations', ['id', 'migration', 'batch']);

        $this->assertSame(['notes'], $this->discoveredTables());

        $target = $this->workspace->path() . '/dump.php';

        $this->command()->handle([], ['output' => $target]);

        $contents = (string) file_get_contents($target);

        $this->assertStringContainsString("Schema::create('notes'", $contents);
        $this->assertStringNotContainsString('options', $contents);
        $this->assertStringNotContainsString('plugin_migrations', $contents);
    }

    /**
     * A table whose name is not prefixed is not the project's.
     *
     * Every WordPress table carries `$wpdb->prefix`; a table without it belongs
     * to something else on the same server, and the plugin has no business
     * dumping it — the `.env`-configured prefix is the whole of its claim on the
     * database.
     *
     * The name here is chosen to *contain* the prefix rather than merely lack it,
     * which is the case that matters. A check written as "strip the prefix if it
     * is there" turns `leadbridge_notes` into `bridge_notes` — a table that does
     * not exist and never did.
     *
     * The assertion is on the *names the command selects*, not on the file it
     * writes, and the difference is the whole point. The truncated phantom is
     * absent from the database under that name, so the dumper refuses it and no
     * file appears either way — meaning an assertion on the file would pass with
     * the prefix check deleted, and would be pinning the dumper's guard while
     * claiming to pin the command's. What the check actually decides is which
     * tables get named in the first place, so that is what is tested.
     */
    public function test_a_table_without_the_prefix_is_not_discovered(): void
    {
        $this->db->createTable('leadbridge_notes', ['id']);
        $this->db->setColumnMeta('leadbridge_notes', 'id', [
            'Type' => 'bigint(20) unsigned',
            'Null' => 'NO',
            'Extra' => 'auto_increment',
        ]);

        $this->assertSame(
            [],
            $this->discoveredTables(),
            'A table outside the prefix must not be mistaken for one of ours, and must not be '
            . 'truncated into a phantom name.'
        );
    }

    /**
     * A dump with nothing to write says so rather than writing an empty file.
     *
     * An empty migration is not merely useless: the migrator would record it as
     * applied, and a developer reading the directory would see a migration that
     * appears to do nothing. An error is the honest outcome.
     */
    public function test_a_dump_with_no_tables_reports_rather_than_writing(): void
    {
        $target = $this->workspace->path() . '/dump.php';

        $command = $this->command();
        $command->handle([], ['output' => $target]);

        $this->assertFileDoesNotExist($target);
        $this->assertStringContainsString('nothing', strtolower(implode("\n", $command->messages)));
    }

    /**
     * The dump is reported, with the count and the path.
     *
     * A snapshot command that wrote a file silently would leave the operator
     * unsure whether it ran at all — and the path is what they need next.
     */
    public function test_it_reports_the_path_it_wrote_and_how_many_tables(): void
    {
        $this->db->createTable('wp_notes', ['id']);
        $this->db->createTable('wp_leads', ['id']);

        $target = $this->workspace->path() . '/dump.php';

        $command = $this->command();
        $command->handle([], ['output' => $target, 'table' => ['notes', 'leads']]);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString('2 table(s)', $output);
        $this->assertStringContainsString($target, $output);
    }

    /**
     * The command states that the dump is a snapshot, not a history.
     *
     * The one thing a reader must not conclude from the output is that it
     * reconstructs the migrations that produced the schema. It does not — it
     * describes what is there — and a caveat printed at the point of use is
     * where that belongs.
     */
    public function test_it_says_the_dump_is_a_snapshot_not_a_history(): void
    {
        $this->db->createTable('wp_notes', ['id']);

        $target = $this->workspace->path() . '/dump.php';

        $command = $this->command();
        $command->handle([], ['output' => $target, 'table' => ['notes']]);

        $this->assertStringContainsString(
            'snapshot',
            strtolower((string) file_get_contents($target)),
            'The generated file must say what it is, since it is read away from this conversation.'
        );
    }

    /**
     * An unwritable path is reported rather than thrown.
     *
     * The same contract every other migration command has: a failure is a
     * message and an exit, not a stack trace in the operator's terminal.
     *
     * The path points *inside a file* rather than at a missing directory. A
     * missing directory is not a failure at all — the command creates it, and
     * rightly so, since `--output=database/dumps/schema.php` on a fresh checkout
     * names a directory nobody has made yet. Making that the failure case would
     * have pinned the opposite of the intended behaviour and passed only by
     * accident on a platform that refused the `mkdir`. A path under an existing
     * *file* cannot be created on any platform, so the failure here is the one
     * the test claims to be testing.
     */
    public function test_an_unwritable_path_is_reported(): void
    {
        $this->db->createTable('wp_notes', ['id']);

        $blocker = $this->workspace->path() . '/not-a-directory';
        file_put_contents($blocker, 'occupied');

        $command = $this->command();
        $command->handle([], ['output' => $blocker . '/dump.php', 'table' => ['notes']]);

        $output = strtolower(implode("\n", $command->messages));

        $this->assertStringContainsString('writ', $output, 'The operator has to learn the write failed.');
        $this->assertFileDoesNotExist($blocker . '/dump.php');
    }

    /**
     * A missing directory is created, since `--output` routinely names one.
     *
     * Pinned separately from the failure above, and deliberately: the two are
     * easy to conflate, and a command that reported "cannot write" for every path
     * whose parent did not exist yet would be unusable for the documented
     * `--output=database/dumps/schema.php` invocation.
     */
    public function test_a_missing_destination_directory_is_created(): void
    {
        $this->db->createTable('wp_notes', ['id']);

        $target = $this->workspace->path() . '/fresh/nested/dump.php';

        $this->command()->handle([], ['output' => $target, 'table' => ['notes']]);

        $this->assertFileExists($target);
    }

    // ----------------------------------------------------------------- helpers

    /**
     * The destination flag must not be named `path`.
     *
     * `--path` is WP-CLI's own global — `wp --path=/srv/site <command>` picks the
     * installation to run against — and WP-CLI consumes it during bootstrap,
     * before any command sees its arguments. A command that declared `--path` as
     * its own option did not shadow the global, it lost to it: the value was read
     * as a WordPress root, the command never received the flag, and the operator
     * was told to "pass a different --path" while passing one.
     *
     * Pinned as a test rather than left to the docblock because the collision is
     * invisible from inside the suite — every test calls `handle()` directly and
     * so never goes through WP-CLI's argument parsing at all. Nothing else here
     * would fail if the name were changed back.
     */
    public function test_the_destination_flag_is_not_named_path(): void
    {
        $this->db->createTable('wp_notes', ['id']);

        $target = $this->workspace->path() . '/flag-name.php';

        // Passed under the WP-CLI global's name, which the command must ignore.
        $command = $this->command();
        $command->handle([], ['path' => $target, 'table' => ['notes']]);

        $this->assertFileDoesNotExist($target, '--path was honoured; it belongs to WP-CLI and must not be read as this flag.');
        $this->assertStringContainsString('--output', implode("\n", $command->messages));
    }

    private function command(): TestableDumpCommand
    {
        return new TestableDumpCommand(new SchemaDumper(new WpDatabaseAdapter($this->db)));
    }

    /**
     * The table names the command's default discovery settles on.
     *
     * Reached by reflection rather than through `handle()`, because discovery is
     * a *decision* and `handle()` folds it together with the dumping and the
     * writing. Where those three agree, a test driven through `handle()` cannot
     * say which one produced the outcome it sees — and the prefix check in
     * particular is invisible from the outside, since a wrongly-included table is
     * rejected downstream by the dumper for an unrelated reason.
     *
     * @return list<string>
     */
    private function discoveredTables(): array
    {
        $method = new \ReflectionMethod(MigrateDumpCommand::class, 'discoverTables');
        $method->setAccessible(true);

        /** @var list<string> $tables */
        $tables = $method->invoke($this->command());

        return $tables;
    }
}

/**
 * The real command with its output captured.
 *
 * Only the reporting helpers are overridden; `handle()` is inherited unchanged,
 * so what runs here is the shipped behaviour.
 */
final class TestableDumpCommand extends MigrateDumpCommand
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
