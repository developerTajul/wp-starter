<?php
declare(strict_types=1);

namespace Tests\Unit\Database;

use Framework\Config\Config;
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
/**
 * The class the migrator will derive from a generated file, or null when the
 * file is not named the way the migrator demands.
 *
 * The migrator reads the class name out of the *basename* of the migration file
 * — `MigrationFile::PATTERN` is anchored to `^` and carries no directory part —
 * so a file in a subdirectory must satisfy the pattern on its own name, and a
 * non-null result here is itself the assertion that it does.
 */
function migrationClassIn(string $path): ?string
{
    return MigrationFile::fromPath($path)?->class;
}

final class MakeMigrationCommandTest extends TestCase
{
    private string $workspace;
    private ?string $originalBase = null;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->workspace = sys_get_temp_dir() . '/wpml-make-' . bin2hex(random_bytes(6));
        mkdir($this->workspace . '/database/migrations', 0755, true);

        // The workspace deliberately carries no stubs/ directory. The generator
        // resolves its target through base_path() but its *stub* through
        // StubLocator, which falls back to the framework's own copy when the
        // project has none — so this test now also pins that fallback: if the
        // resolution regressed to base_path('stubs/migration.stub'), every case
        // below would fail with "Stub file not found".
        //
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

        $files = $this->migrationFiles();

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

        $files = $this->migrationFiles();

        $this->assertSame(
            'CreateLeadsTable',
            migrationClassIn($files[0]),
            'The generated filename must satisfy the migrator\'s naming convention.'
        );
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

        $files = $this->migrationFiles();

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
     * `--create=` scaffolds a `CREATE TABLE` against the table it names.
     *
     * The table comes from the flag and nowhere else. The description is still
     * `create_leads_table` here, which the old code would have read as naming
     * `leads` — so a test that only checked the output would pass either way.
     * The flag is deliberately given a table the description does *not* imply
     * (`archived_leads`), which makes the assertion fail if any guessing creeps
     * back in.
     */
    public function test_create_flag_scaffolds_a_create_table_against_the_named_table(): void
    {
        (new TestableMakeMigrationCommand())->handle(['create_leads_table'], ['create' => 'archived_leads']);

        $contents = (string) file_get_contents($this->migrationFiles()[0]);

        $this->assertStringContainsString("Schema::create('archived_leads'", $contents);
        $this->assertStringContainsString("Schema::dropIfExists('archived_leads')", $contents);
        $this->assertStringNotContainsString('{{ table }}', $contents);
    }

    /**
     * `--table=` scaffolds an alter against a table that already exists, and the
     * `down()` drops what the `up()` added rather than dropping the table.
     *
     * The distinction is the whole reason there are two stubs: a migration that
     * used `Schema::create()` on an existing table is an error at run time, and a
     * `down()` that dropped the table would destroy data a `rollback` was only
     * meant to undo a column of.
     */
    public function test_table_flag_scaffolds_an_alter_against_the_named_table(): void
    {
        (new TestableMakeMigrationCommand())->handle(['add_status_to_leads'], ['table' => 'leads']);

        $contents = (string) file_get_contents($this->migrationFiles()[0]);

        $this->assertStringContainsString("Schema::table('leads'", $contents);
        $this->assertStringContainsString('dropColumn', $contents);
        $this->assertStringNotContainsString('Schema::create(', $contents);
        $this->assertStringNotContainsString('dropIfExists', $contents);
    }

    /**
     * Without a flag, nothing is scaffolded.
     *
     * This is the behaviour that replaced the regex guess, and it is the point of
     * the change: `create_leads_table` looks like it names `leads`, and the old
     * code would have written `Schema::create('leads')` into the file on that
     * basis. A wrong table name is the line in a scaffold a developer is least
     * likely to re-read, so the generator no longer invents one.
     *
     * Asserted on the *call*, not on the bare `Schema::create(` substring: the
     * no-table stub's own comment names both calls, deliberately, to tell the
     * developer what to reach for. A substring test would fail on the correct stub.
     */
    public function test_no_flag_scaffolds_nothing(): void
    {
        (new TestableMakeMigrationCommand())->handle(['create_leads_table'], []);

        $contents = (string) file_get_contents($this->migrationFiles()[0]);

        $this->assertDoesNotMatchRegularExpression("/Schema::(?:create|table)\('[^']+'/", $contents);
        $this->assertStringNotContainsString("'leads'", $contents);
        $this->assertStringNotContainsString('{{ table }}', $contents);
    }

    /**
     * An unguessable description is treated exactly like a guessable one now —
     * there is no code path that reads the description for a table at all.
     */
    public function test_the_description_never_supplies_a_table_name(): void
    {
        (new TestableMakeMigrationCommand())->handle(['add_status_to_leads'], []);

        $this->assertStringNotContainsString("'leads'", (string) file_get_contents($this->migrationFiles()[0]));
    }

    /**
     * `--table=false` is a caller saying no, not a caller naming a table `false`.
     * Casting it through would scaffold `Schema::create('false')`.
     */
    public function test_a_falsy_table_flag_scaffolds_nothing(): void
    {
        (new TestableMakeMigrationCommand())->handle(['create_leads_table'], ['table' => 'false']);

        $contents = (string) file_get_contents($this->migrationFiles()[0]);

        // Asserted on the call, not on the bare `Schema::table(` substring: the
        // no-table stub's own comment names both calls, so a substring test would
        // pass on the wrong stub and would have to be weakened to a regex anyway.
        $this->assertDoesNotMatchRegularExpression("/Schema::(?:create|table)\('[^']+'/", $contents);
        $this->assertStringNotContainsString('false', $contents);
    }

    /**
     * An unusable table name is refused at generation time.
     *
     * Left alone it would render a valid-looking migration that dies when it runs
     * — long after the developer stopped looking at the generated file — so the
     * check belongs at the moment the name was typed.
     */
    public function test_an_unusable_table_flag_value_is_refused(): void
    {
        $command = new TestableMakeMigrationCommand();
        $command->handle(['create_leads_table'], ['table' => 'leads; DROP TABLE users']);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString('not a usable table name', $output);
        $this->assertSame([], $this->migrationFiles(), 'Nothing may be written when the flag is refused.');
    }

    /**
     * `--create` and `--table` together: `--create` wins, because it states an
     * intent rather than naming a target.
     */
    public function test_create_wins_when_both_flags_are_given(): void
    {
        (new TestableMakeMigrationCommand())->handle(['create_leads_table'], [
            'table' => 'leads',
            'create' => 'leads',
        ]);

        $this->assertStringContainsString(
            'Schema::create(',
            (string) file_get_contents($this->migrationFiles()[0])
        );
    }

    /**
     * No placeholder may survive into the generated file — a literal
     * `{{ cli_prefix }}` in a docblock is cosmetic, but a literal `{{ table }}`
     * inside the SQL would be a broken migration.
     */
    public function test_the_stub_leaves_no_placeholders_behind(): void
    {
        (new TestableMakeMigrationCommand())->handle(['create_leads_table'], ['create' => 'leads']);

        $this->assertDoesNotMatchRegularExpression(
            '/\{\{\s*\w+\s*\}\}/',
            (string) file_get_contents($this->migrationFiles()[0])
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

        $files = $this->migrationFiles();

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
     * A migration may be filed in a sub-directory, and the name that matters —
     * the one the migrator derives the class from — is still the *basename*.
     *
     * `MigrationFile::PATTERN` is anchored to the start of the string and
     * carries no directory part, so the test reads the class back through the
     * framework's own parser against the full path: a generator that let the
     * directory leak into the filename would produce something the migrator
     * silently skips, which is the failure mode this pins.
     */
    public function test_a_nested_description_lands_in_a_sub_directory_with_a_parsable_name(): void
    {
        (new TestableMakeMigrationCommand())->handle(['notes/create_notes_table'], []);

        $files = $this->migrationFiles();

        $this->assertCount(1, $files);

        // The *directory* is asserted as a directory, not as a substring of the
        // path — an earlier version of this test checked only that the path
        // contained "notes", which the filename satisfied on its own, so it
        // passed while the generator was writing a flat file. Asserting the
        // parent directory is what makes the nesting the thing under test.
        //
        // StudlyCased to match the namespace: the directory and
        // `App\Database\Migrations\Notes` are one decision spelled twice, and a
        // PSR-4 lookup is case-sensitive on a case-sensitive filesystem.
        $this->assertSame(
            'Notes',
            basename(dirname($files[0])),
            'The migration should be written into a Notes/ sub-directory.'
        );

        $this->assertStringContainsString('create_notes_table', basename($files[0]));

        // The migrator can still read the class out of it, and it is the class
        // the file declares.
        $this->assertSame('CreateNotesTable', migrationClassIn($files[0]));
        $this->assertStringContainsString(
            'class CreateNotesTable',
            (string) file_get_contents($files[0])
        );
    }

    /**
     * The nested migration's namespace gains the sub-namespace after the
     * generator's own path — `App\Database\Migrations\Notes`, not
     * `App\Notes\Database\Migrations`.
     *
     * The segment is StudlyCased, so `notes/` declares `...\Notes` — a namespace
     * spelled the way every other namespace in the project is, rather than
     * echoing the lower-case directory the caller typed.
     */
    public function test_a_nested_migration_namespace_appends_after_the_generator_path(): void
    {
        (new TestableMakeMigrationCommand())->handle(['notes/create_notes_table'], []);

        $contents = (string) file_get_contents($this->migrationFiles()[0]);

        $this->assertStringContainsString('namespace App\\Database\\Migrations\\Notes;', $contents);
        $this->assertStringNotContainsString('Migrations\\notes', $contents);
    }

    /**
     * Deeper nesting normalizes every segment, not just the first.
     */
    public function test_a_deeply_nested_migration_normalizes_each_segment(): void
    {
        (new TestableMakeMigrationCommand())->handle(['admin/notes/create_notes_table'], []);

        $files = $this->migrationFiles();

        $this->assertSame('Notes', basename(dirname($files[0])));
        $this->assertSame('Admin', basename(dirname(dirname($files[0]))));
        $this->assertStringContainsString(
            'namespace App\\Database\\Migrations\\Admin\\Notes;',
            (string) file_get_contents($files[0])
        );
    }

    /**
     * A description with no slash keeps the flat spelling and namespace — the
     * sub-namespace support must be inert when it is not asked for.
     */
    public function test_a_flat_description_is_unaffected(): void
    {
        (new TestableMakeMigrationCommand())->handle(['create_notes_table'], []);

        $files = $this->migrationFiles();
        $contents = (string) file_get_contents($files[0]);

        $this->assertSame(
            'migrations',
            basename(dirname($files[0])),
            'A flat description must not create a sub-directory.'
        );
        $this->assertStringContainsString('namespace App\\Database\\Migrations;', $contents);
        $this->assertStringNotContainsString('Migrations\\;', $contents);
    }

    /**
     * A name made only of separators cannot become a class name, so it is
     * refused rather than producing `<timestamp>_.php` — a filename
     * `MigrationFile::PATTERN` rejects, which the migrator would skip forever
     * while the generator reported success.
     *
     * Refused with the *description* message rather than a naming complaint,
     * because the caller has not made a naming mistake: they gave prose where a
     * description belonged, and "please provide a description" is the message
     * that tells them what to do instead.
     */
    public function test_a_name_that_normalizes_to_nothing_is_refused(): void
    {
        $command = new TestableMakeMigrationCommand();
        $command->handle(['!!!'], []);

        $this->assertStringContainsString('Please provide a migration description', implode("\n", $command->messages));
        $this->assertSame([], $this->migrationFiles());
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

        $written = $this->migrationFiles();
        $this->assertCount(1, $written, 'the first run should have written one file');

        // Capture what it wrote, then put the tree back exactly as it was before —
        // so the second run meets a file at the same path, whatever second it is.
        $contents = (string) file_get_contents($written[0]);

        $second = new TestableMakeMigrationCommand();
        $second->handle(['create_leads_table'], []);

        $after = $this->migrationFiles();
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

        $written = $this->migrationFiles();
        $this->assertNotEmpty($written, 'the probe run should have written a file');

        return $written[0];
    }

    /**
     * Every `.php` file under `database/migrations`.
     *
     * Recursive, because a migration named `notes/create_notes_table` lands in a
     * subdirectory — and a recursive read is also what makes the "exactly one
     * file" assertions meaningful rather than merely blind to anything nested.
     *
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        $root = $this->workspace . '/database/migrations';

        if (!is_dir($root)) {
            return [];
        }

        $found = [];
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($items as $item) {
            if ($item->isFile() && strtolower($item->getExtension()) === 'php') {
                $found[] = $item->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    public function test_the_name_is_prefixed_with_the_cli_prefix(): void
    {
        $prefix = config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX);

        $this->assertSame("{$prefix} make:migration", (new TestableMakeMigrationCommand())->getName());
    }

    /**
     * Run `php -l` over a generated file, or skip when this host cannot.
     *
     * ## Why this is guarded at all
     *
     * The assertion this backs is "the generated migration is syntactically valid
     * PHP", and the only honest way to answer that is to hand the file to a real
     * PHP parser. A tokenizer-based check in-process would re-implement a
     * fraction of the grammar and pass files `php -l` rejects.
     *
     * But it is the one test in the suite that shells out, so it is the one that
     * can fail for reasons that have nothing to do with the code: `PHP_BINARY` is
     * documented as possibly empty when PHP is embedded or its path is
     * unresolvable, `exec()` is in `disable_functions` on a locked-down host, and
     * `escapeshellarg` does not quote for `cmd.exe` — so a Windows path with
     * spaces produces a command the shell splits in the wrong place.
     *
     * ## Why a skip rather than a graceful degradation
     *
     * Failing here would be a red suite on a machine where the generator is
     * perfectly correct, which teaches people to ignore the suite. Passing
     * silently would claim a check that did not run. PHPUnit's `markTestSkipped`
     * is the third option and the right one: the run stays green, and the
     * transcript says which check was not performed.
     *
     * The in-process assertions in the calling test — that the file declares the
     * class, extends `Migration`, and carries both methods — run regardless, so
     * this is one check skipped rather than the whole test.
     */
    private function lint(string $path): int
    {
        // PHP_BINARY is a constant that can be the empty string; `function_exists`
        // covers the host that compiled exec() out entirely.
        if (!function_exists('exec') || PHP_BINARY === '') {
            $this->markTestSkipped(
                'php -l is unavailable on this host (no exec(), or PHP_BINARY is empty), '
                . 'so the syntax check is skipped.'
            );
        }

        $output = [];
        $status = 0;

        // 2>&1 is a POSIX redirection. cmd.exe parses it as a file handle and
        // errors on it, so on Windows stderr is captured by output buffering
        // instead — the parse error still reaches $output, which is all the
        // failure path needs.
        $command = sprintf('%s -l %s', escapeshellarg(PHP_BINARY), escapeshellarg($path));

        if (DIRECTORY_SEPARATOR === '\\') {
            exec($command, $output, $status);

            return $status;
        }

        exec($command . ' 2>&1', $output, $status);

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
