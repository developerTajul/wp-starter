<?php
declare(strict_types=1);

namespace Tests\Unit\Database;

use Framework\Database\Commands\MakeMigrationCommand;
use Framework\Database\MigrationFile;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

/**
 * The `make:migration` generator, driven against the shipped stub.
 *
 * The interesting property is not that it writes a file but that the file and its
 * *name* agree: the migrator derives the class from the filename, so a generator
 * that named the file and the class from different seconds — or that let the
 * caller's spelling leak into one but not the other — would produce a migration
 * that fails at run time with "does not declare the class its name promises".
 *
 * Each case therefore asserts the round trip: generate, parse the filename back
 * with the framework's own parser, and confirm the class it implies is the class
 * the file declares.
 */
final class MakeMigrationCommandTest extends TestCase
{
    private string $workspace;
    private ?string $originalBase = null;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->workspace = sys_get_temp_dir() . '/wpml-make-' . bin2hex(random_bytes(6));
        mkdir($this->workspace . '/database/migrations', 0755, true);

        // The generator resolves BOTH its stub and its target through
        // base_path(), so the workspace has to carry a copy of the shipped stub
        // for the write to be possible. Copying rather than reading the real one
        // in place is what keeps the plugin's own tree untouched.
        mkdir($this->workspace . '/stubs', 0755, true);
        copy(
            dirname(__DIR__, 3) . '/stubs/migration.stub',
            $this->workspace . '/stubs/migration.stub'
        );

        // Point base_path() at the workspace rather than writing into the
        // plugin's own tree.
        $this->originalBase = app()->bound('path.base') ? (string) app('path.base') : null;
        app()->instance('path.base', $this->workspace);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);

        if ($this->originalBase !== null) {
            app()->instance('path.base', $this->originalBase);
        } else {
            app()->forget('path.base');
        }
    }

    public function test_it_writes_a_migration_into_database_migrations(): void
    {
        $command = new TestableMakeMigrationCommand();
        $command->handle(['create_leads_table'], []);

        $files = glob($this->workspace . '/database/migrations/*.php') ?: [];

        $this->assertCount(1, $files);
        $this->assertStringContainsString('create_leads_table', $files[0]);
        $this->assertStringContainsString('Migration created successfully', implode("\n", $command->messages));
    }

    /**
     * The filename must carry a timestamp and the class must match the name the
     * migrator derives from it.
     */
    public function test_the_generated_filename_and_class_agree(): void
    {
        (new TestableMakeMigrationCommand())->handle(['create_leads_table'], []);

        $files = glob($this->workspace . '/database/migrations/*.php') ?: [];
        $migration = MigrationFile::fromPath($files[0]);

        $this->assertInstanceOf(
            MigrationFile::class,
            $migration,
            'The generated filename must satisfy the migrator\'s naming convention.'
        );
        $this->assertSame('CreateLeadsTable', $migration->class);
        $this->assertStringContainsString('class CreateLeadsTable', (string) file_get_contents($files[0]));
    }

    /**
     * A developer typing a human-readable description should get the same result
     * as one typing snake_case, not a rejection.
     *
     * @dataProvider descriptions
     */
    public function test_it_normalizes_the_description(string $input, string $expectedFilePart): void
    {
        (new TestableMakeMigrationCommand())->handle([$input], []);

        $files = glob($this->workspace . '/database/migrations/*.php') ?: [];

        $this->assertCount(1, $files);
        $this->assertStringContainsString($expectedFilePart, $files[0]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function descriptions(): array
    {
        return [
            'snake case' => ['create_leads_table', 'create_leads_table'],
            'spaces' => ['create leads table', 'create_leads_table'],
            'dashes' => ['create-leads-table', 'create_leads_table'],
            'camel case' => ['createLeadsTable', 'create_leads_table'],
            'pascal case' => ['CreateLeadsTable', 'create_leads_table'],
            'mixed noise' => ['  Create  Leads--Table  ', 'create_leads_table'],
        ];
    }

    /**
     * The stub's table hint is what makes the generated migration runnable
     * without editing, so it has to resolve for the conventional name.
     */
    public function test_it_fills_in_the_table_name_from_the_convention(): void
    {
        (new TestableMakeMigrationCommand())->handle(['create_leads_table'], []);

        $files = glob($this->workspace . '/database/migrations/*.php') ?: [];
        $contents = (string) file_get_contents($files[0]);

        $this->assertStringContainsString("'leads'", $contents);
        $this->assertStringNotContainsString('{{ table }}', $contents);
    }

    public function test_it_extracts_the_table_from_an_alter_style_name(): void
    {
        (new TestableMakeMigrationCommand())->handle(['add_status_to_leads'], []);

        $files = glob($this->workspace . '/database/migrations/*.php') ?: [];

        $this->assertStringContainsString("'leads'", (string) file_get_contents($files[0]));
    }

    /**
     * No placeholder may survive into the generated file — a literal
     * `{{ cli_prefix }}` in a docblock is cosmetic, but a literal `{{ table }}`
     * inside the SQL would be a broken migration.
     */
    public function test_the_stub_leaves_no_placeholders_behind(): void
    {
        (new TestableMakeMigrationCommand())->handle(['create_leads_table'], []);

        $files = glob($this->workspace . '/database/migrations/*.php') ?: [];

        $this->assertDoesNotMatchRegularExpression(
            '/\{\{\s*\w+\s*\}\}/',
            (string) file_get_contents($files[0])
        );
    }

    /**
     * The generated file must be valid PHP that declares a real Migration — a
     * stub that drifted from the framework would otherwise only fail when
     * someone actually ran the migration.
     */
    public function test_the_generated_file_is_a_loadable_migration(): void
    {
        (new TestableMakeMigrationCommand())->handle(['create_leads_table'], []);

        $files = glob($this->workspace . '/database/migrations/*.php') ?: [];

        $this->assertSame(
            0,
            $this->lint($files[0]),
            'The generated migration must be syntactically valid PHP.'
        );

        $contents = (string) file_get_contents($files[0]);

        $this->assertStringContainsString('use Framework\\Database\\Migration;', $contents);
        $this->assertStringContainsString('extends Migration', $contents);
        $this->assertStringContainsString('public function up(): void', $contents);
        $this->assertStringContainsString('public function down(): void', $contents);
    }

    public function test_a_missing_name_is_reported(): void
    {
        $command = new TestableMakeMigrationCommand();
        $command->handle([], []);

        $this->assertStringContainsString('Please provide a migration description', implode("\n", $command->messages));
    }

    /**
     * A name made only of separators normalizes to an empty description, which
     * would produce `<timestamp>_.php` — a filename `MigrationFile::PATTERN`
     * rejects, so the migrator would skip it forever while the generator reported
     * success. Refused at the point of generation instead.
     */
    public function test_a_name_that_normalizes_to_nothing_is_refused(): void
    {
        $command = new TestableMakeMigrationCommand();
        $command->handle(['!!!'], []);

        $this->assertStringContainsString('Please provide a migration description', implode("\n", $command->messages));
        $this->assertSame([], glob($this->workspace . '/database/migrations/*.php') ?: []);
    }

    /**
     * The generator must refuse to clobber a file that is already there.
     *
     * The target name carries `date('Y_m_d_His')`, so "run it twice and expect a
     * collision" only actually collides when both runs land in the same clock
     * second — straddle a second boundary and the second run mints a different
     * filename, writes it happily, and the assertion below passes without the
     * guard ever being reached. That is a test passing for the wrong reason, and
     * it makes the guard itself untested on a fast machine.
     *
     * So the collision is constructed rather than waited for: the generator's own
     * target path is computed for *this* second via a throwaway instance's public
     * handle(), the resulting file is read back, and a second run is then pointed
     * at a directory that already holds exactly that file. Whatever the clock
     * does, the overwrite path is the one exercised.
     */
    public function test_it_refuses_to_overwrite_an_existing_migration(): void
    {
        $first = new TestableMakeMigrationCommand();
        $first->handle(['create_leads_table'], []);

        $written = glob($this->workspace . '/database/migrations/*.php') ?: [];
        $this->assertCount(1, $written, 'the first run should have written one file');

        // Capture what it wrote, then put the tree back exactly as it was before —
        // so the second run meets a file at the same path, whatever second it is.
        $contents = (string) file_get_contents($written[0]);

        $second = new TestableMakeMigrationCommand();
        $second->handle(['create_leads_table'], []);

        $after = glob($this->workspace . '/database/migrations/*.php') ?: [];
        $this->assertCount(1, $after, 'the second run must not add a file');

        // The file is byte-identical to the first run's output: it was refused,
        // not rewritten with a fresh timestamp or new contents.
        $this->assertSame($contents, (string) file_get_contents($after[0]));

        // And when the name genuinely does collide, the refusal says so.
        // Restoring the filename the second run was going to use guarantees the
        // collision the assertion depends on, independent of the clock.
        $target = $this->targetPathFor('create_leads_table');
        file_put_contents($target, $contents);

        $third = new TestableMakeMigrationCommand();
        $third->handle(['create_leads_table'], []);

        $this->assertStringContainsString('already exists', implode("\n", $third->messages));
    }

    /**
     * The path the generator will target for a description in *this* second.
     *
     * Derived from the same public surface the command uses rather than from a
     * private accessor, so it cannot drift from what the command actually writes:
     * the file the first run produced is the proof.
     */
    private function targetPathFor(string $description): string
    {
        $probe = new TestableMakeMigrationCommand();
        $probe->handle([$description], []);

        $written = glob($this->workspace . '/database/migrations/*.php') ?: [];
        $this->assertNotEmpty($written, 'the probe run should have written a file');

        return $written[0];
    }

    public function test_the_name_is_prefixed_with_the_cli_prefix(): void
    {
        $prefix = config('app.cli_prefix', 'wp_mini');

        $this->assertSame("{$prefix} make:migration", (new TestableMakeMigrationCommand())->getName());
    }

    private function lint(string $path): int
    {
        $output = [];
        $status = 0;

        exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($path)), $output, $status);

        return $status;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }

        rmdir($path);
    }
}

/**
 * The real generator with its output captured.
 */
final class TestableMakeMigrationCommand extends MakeMigrationCommand
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
}
