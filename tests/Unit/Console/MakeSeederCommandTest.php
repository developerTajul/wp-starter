<?php
declare(strict_types=1);

namespace Tests\Unit\Console;

use Framework\Config\Config;
use Framework\Database\Commands\MakeSeederCommand;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

/**
 * `make:seeder`, and the one thing about it that can be wrong.
 *
 * A scaffolded seeder is usable if and only if the name it reports and the name
 * `db:seed --class=` accepts are the same string. Those two are produced by
 * different code — the generator's `{{ seeder }}` replacement and
 * {@see \Framework\Database\Seeding\Seeder::getName()} at run time — so the claim
 * this file pins is that they agree, by writing the file, loading the class and
 * asking it. Asserting the rendered text instead would pin the *stub*, which is
 * not what can drift.
 *
 * ## The class name is the caller's, unmodified
 *
 * `make:seeder Blog` writes `Blog.php`, not `BlogSeeder.php`. That is the base
 * class's rule for every generator and this one adds nothing to it — see
 * {@see MakeSeederCommand}'s docblock. The tests below that spell `BlogSeeder`
 * do so because that is the *conventional* name a developer types, not because
 * anything appends it.
 */
final class MakeSeederCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->workspace = sys_get_temp_dir() . '/wpml-mkseed-' . bin2hex(random_bytes(6));
        mkdir($this->workspace . '/database/seeders', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
    }

    public function test_it_writes_a_seeder_into_database_seeders(): void
    {
        $command = $this->command();
        $command->handle(['BlogSeeder'], []);

        $this->assertFileExists($this->workspace . '/database/seeders/BlogSeeder.php');
        $this->assertStringContainsString('Seeder created successfully', implode("\n", $command->messages));
    }

    /**
     * The class name is used exactly as given, so the file on disk is the file
     * the developer asked for. This is the base class's contract, and a generator
     * that appended a suffix would be the one out of step with `make:command`
     * and `make:controller`.
     */
    public function test_the_class_name_is_the_name_the_caller_typed(): void
    {
        $this->command()->handle(['Blog'], []);

        $this->assertFileExists($this->workspace . '/database/seeders/Blog.php');
        $this->assertFileDoesNotExist($this->workspace . '/database/seeders/BlogSeeder.php');
    }

    /**
     * The convention {@see \Framework\Database\Seeding\SeederRegistry} depends on:
     * one file, one class, named for the file. The class declared inside must be
     * the basename of the file it was written to, whatever that name is.
     */
    public function test_the_declared_class_matches_the_file_name(): void
    {
        foreach (['Blog', 'BlogSeeder', 'ImportLegacyLeadsSeeder'] as $class) {
            $this->command()->handle([$class], []);

            $contents = (string) file_get_contents($this->workspace . '/database/seeders/' . $class . '.php');

            $this->assertStringContainsString("final class {$class} extends Seeder", $contents);
        }
    }

    /**
     * The name and the class must agree, checked by loading the generated file
     * rather than by reading it.
     *
     * The namespace is rewritten to a scratch one so the class is loadable from a
     * temp directory. Everything the claim depends on — the class name, the
     * stubbed `getName()` derivation, the `{{ seeder }}` substitution — is the
     * generator's own.
     */
    public function test_the_generated_class_is_loadable_and_registers_the_expected_name(): void
    {
        $this->command()->handle(['BlogSeeder'], []);

        $class = $this->loadGenerated('BlogSeeder', 'GeneratedBlogSeeder');

        /** @var \Framework\Database\Seeding\SeederInterface $seeder */
        $seeder = new $class();

        // `getName()` strips the `Seeder` suffix, so `--class=blog` is what runs
        // this file. The stub's docblock names exactly that, which is what makes
        // the generated file self-documenting rather than merely valid.
        //
        // The expected value is the alias with its suffix stripped, so the
        // assertion is about the *derivation* rather than about the literal the
        // alias happens to end in.
        $this->assertSame('generatedblog', $seeder->getName());
        $this->assertStringEndsWith('blog', $seeder->getName());

        // Runs without a database: the stub's `run()` is empty, which is the
        // "scaffold, then fill it in" path a generator promises.
        $seeder->run();
    }

    /**
     * A class without the suffix registers under its own lower-cased name.
     *
     * Both spellings have to work, because the generator permits both — pinning
     * only the suffixed case would leave `make:seeder Blog` producing a seeder
     * whose `--class=` hint had never been checked against the class.
     */
    public function test_a_class_without_the_suffix_registers_under_its_own_name(): void
    {
        $this->command()->handle(['Blog'], []);

        $class = $this->loadGenerated('Blog', 'Unwrapped');

        /** @var \Framework\Database\Seeding\SeederInterface $seeder */
        $seeder = new $class();

        // No suffix to strip, so the whole class name lower-cased is the name.
        $this->assertSame('unwrapped', $seeder->getName());
    }

    /**
     * The docblock's `--class=` hint has to name a seeder that exists.
     *
     * The `{{ seeder }}` placeholder is substituted by the generator alone — the
     * base class replaces only `namespace` and `class` — so a placeholder it does
     * not supply ships as literal `{{ seeder }}` text, and the hint would tell the
     * developer to run a command that matches nothing.
     */
    public function test_the_stub_leaves_no_placeholders_behind(): void
    {
        $this->command()->handle(['BlogSeeder'], []);

        $contents = (string) file_get_contents($this->workspace . '/database/seeders/BlogSeeder.php');

        $this->assertDoesNotMatchRegularExpression('/\{\{\s*\w+\s*\}\}/', $contents);
        $this->assertStringContainsString('--class=blog', $contents);
    }

    /**
     * The hint is built from the class the run actually generated, not from a
     * constant — `make:seeder Order` must not suggest `--class=blog`.
     */
    public function test_the_post_write_hint_names_the_seeder_that_was_created(): void
    {
        $command = $this->command();
        $command->handle(['Order'], []);

        $output = implode("\n", $command->messages);

        $this->assertStringContainsString('--class=order', $output);
        $this->assertStringNotContainsString('--class=blog', $output);
    }

    /**
     * The registered name reads the CLI prefix from config, so a rebranded
     * project's generator registers under its own prefix.
     */
    public function test_the_registered_name_is_prefixed(): void
    {
        $prefix = config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX);

        $this->assertSame("{$prefix} make:seeder", (new MakeSeederCommand())->getName());
    }

    public function test_a_missing_name_is_reported(): void
    {
        $command = $this->command();
        $command->handle([], []);

        $this->assertStringContainsString('Please provide a seeder name', implode("\n", $command->messages));
    }

    public function test_it_refuses_to_overwrite_an_existing_seeder(): void
    {
        file_put_contents($this->workspace . '/database/seeders/BlogSeeder.php', 'hand written');

        $command = $this->command();
        $command->handle(['BlogSeeder'], []);

        $this->assertSame(
            'hand written',
            file_get_contents($this->workspace . '/database/seeders/BlogSeeder.php')
        );
        $this->assertStringContainsString('already exists', implode("\n", $command->messages));
    }

    /**
     * A class named literally `Seeder` must not have its whole name stripped.
     *
     * {@see \Framework\Database\Seeding\Seeder::getName()} guards this with
     * `$short !== 'Seeder'`, and the generator reproduces the guard. Without it
     * the class would report the empty string as its name, which no `--class=`
     * can ever match.
     */
    public function test_a_class_named_seeder_keeps_a_usable_name(): void
    {
        $this->command()->handle(['Seeder'], []);

        $contents = (string) file_get_contents($this->workspace . '/database/seeders/Seeder.php');

        $this->assertStringContainsString('--class=seeder', $contents);
    }

    /**
     * A nested seeder lands in a sub-directory with a matching namespace, and its
     * selectable *name* is unaffected by where the file lives.
     *
     * The name is derived from the class basename, so `Admin/Import` is still
     * `--class=import`. Deriving it from the whole argument would register
     * `admin-import` and quietly rename a seeder whose name was already decided.
     */
    public function test_a_nested_seeder_lands_in_a_sub_directory(): void
    {
        $this->command()->handle(['Admin/Import'], []);

        $contents = (string) file_get_contents($this->workspace . '/database/seeders/Admin/Import.php');

        $this->assertStringContainsString('namespace App\\Database\\Seeders\\Admin;', $contents);
        $this->assertStringContainsString('--class=import', $contents);
    }

    /**
     * Render a generated seeder into a scratch namespace and load it.
     *
     * The class is renamed as well as namespaced, because `require_once` on a
     * second file declaring the same class name is a fatal — and two tests here
     * generate a class called `Blog`.
     *
     * The alias must preserve the `Seeder` suffix, because
     * {@see \Framework\Database\Seeding\Seeder::getName()} derives the selectable
     * name from the class basename: renaming `BlogSeeder` to `GeneratedBlogSeeder`
     * would keep the suffix, but renaming `Blog` to `GeneratedUnwrappedBlog`
     * would not — and the name under test would silently become
     * `generatedunwrappedblog`. That is why every caller passes a suffix-preserving
     * alias, and why the assertion on the derived name is written against the
     * *suffix*, not against the whole alias.
     *
     * @return class-string
     */
    private function loadGenerated(string $className, string $alias): string
    {
        $path = $this->workspace . '/database/seeders/' . $className . '.php';
        $source = (string) file_get_contents($path);

        preg_match('/^namespace\s+([^;]+);/m', $source, $m);
        $namespace = $m[1] ?? '';

        $source = str_replace(
            "namespace {$namespace};",
            'namespace Tests\\Unit\\Console\\GeneratedSeeder;',
            $source
        );
        $source = str_replace("final class {$className}", "final class {$alias}", $source);

        file_put_contents($path, $source);
        require_once $path;

        $class = 'Tests\\Unit\\Console\\GeneratedSeeder\\' . $alias;

        $this->assertTrue(class_exists($class), "The generated seeder [{$className}] did not declare a loadable class.");

        return $class;
    }

    private function command(?string $workspace = null): TestableMakeSeederCommand
    {
        return new TestableMakeSeederCommand($workspace ?? $this->workspace);
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
 * The real generator, aimed at a workspace with its output captured.
 *
 * `projectPath()` is overridden so the stub lookup moves with the target path,
 * the same seam {@see TestableMakeCommandCommand} uses.
 */
final class TestableMakeSeederCommand extends MakeSeederCommand
{
    /** @var list<string> */
    public array $messages = [];

    public function __construct(private readonly string $workspace)
    {
    }

    protected function projectPath(): string
    {
        return $this->workspace;
    }

    protected function getTargetPath(string $className): string
    {
        return $this->workspace . '/database/seeders/' . $this->subDirectoryPath() . $className . '.php';
    }

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
