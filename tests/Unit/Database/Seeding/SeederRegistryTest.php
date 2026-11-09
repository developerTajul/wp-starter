<?php
declare(strict_types=1);

namespace Tests\Unit\Database\Seeding;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Config\Config;
use Framework\Database\Seeding\SeederInterface;
use Framework\Database\Seeding\SeederRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\WpStub;

/**
 * Discovery of the project's seeders.
 *
 * Seeders are written into a temporary directory as real files and `require`d by
 * the registry, so the class-name-from-filename convention is exercised for real
 * rather than stubbed around — the same approach {@see \Tests\Unit\Database\MigratorTest}
 * takes with migrations, and for the same reason: a fixture that pre-registered
 * its classes would pass with discovery completely broken.
 *
 * ## Why every fixture class name carries a random suffix
 *
 * {@see SeederRegistry} names a class from its *filename* and `require`s the file.
 * PHPUnit runs the whole suite in one process, so two tests that each wrote
 * `LeadSeeder.php` — into two different temporary directories — would declare
 * `App\Database\Seeders\LeadSeeder` twice, and the second declaration is a
 * **fatal**: "Cannot declare class … because the name is already in use".
 *
 * `require_once` guards by *path* and the paths differ, so it does not help; the
 * class table is process-wide. Hence {@see fixture()}: the tests are still *about*
 * a lead seeder, and the assertions compare against {@see fixtureName()} rather
 * than a fixed literal.
 *
 * ## What the container is doing here
 *
 * Three of these tests are about the container seam and could not be written
 * against a registry that built its seeders with `new`: that a constructor
 * dependency arrives, that building the map does not run anything, and that a
 * name collision is caught before either seeder runs.
 */
final class SeederRegistryTest extends TestCase
{
    private string $directory;
    private Container $container;
    private string $suffix;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->suffix = bin2hex(random_bytes(4));
        $this->directory = sys_get_temp_dir() . '/wpml-seeders-' . bin2hex(random_bytes(6));

        mkdir($this->directory, 0755, true);

        $this->container = new Container();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    // ------------------------------------------------------------ discovery

    public function test_it_discovers_a_seeder_from_a_file_in_the_directory(): void
    {
        $this->writeSeeder('LeadSeeder');

        $this->assertSame([$this->fixtureName('LeadSeeder')], $this->registry()->names());
    }

    /**
     * The name comes from the instance, not from the filename.
     *
     * {@see SeederInterface::getName()} is overridable, and a seeder that renamed
     * itself must be selectable by the name it chose. Deriving the key from the
     * class would make the override decorative.
     */
    public function test_a_seeder_that_renames_itself_is_keyed_by_its_own_name(): void
    {
        $renamed = 'demo-leads-' . $this->suffix;

        $this->writeSeeder('LeadSeeder', <<<PHP
            public function getName(): string
            {
                return '{$renamed}';
            }

            public function run(): void {}
            PHP);

        $this->assertSame([$renamed], $this->registry()->names());
        $this->assertNotNull($this->registry()->find($renamed));
    }

    /**
     * The lookup is case-insensitive and trims, matching the folding the keying
     * applies. A developer typing `--class=Lead` is not making a distinction
     * between two things.
     */
    public function test_lookup_folds_case_and_trims(): void
    {
        $this->writeSeeder('LeadSeeder');

        $name = $this->fixtureName('LeadSeeder');

        $this->assertNotNull($this->registry()->find($name));
        $this->assertNotNull($this->registry()->find(strtoupper($name)));
        $this->assertNotNull($this->registry()->find('  ' . ucfirst($name) . '  '));
        $this->assertNull($this->registry()->find('nothing-like-it'));
    }

    /**
     * `ksort`ed, so a full run is reproducible.
     *
     * `glob()` documents no order, so without the sort the sequence depends on
     * the filesystem — and a seeder that happened to depend on another would work
     * on one machine and not another.
     *
     * The bases are chosen so the *suffixed* names still sort in the order
     * asserted: the shared random suffix is identical across all three, so it
     * cannot reorder them.
     */
    public function test_names_are_sorted_rather_than_left_in_filesystem_order(): void
    {
        // Written in an order that is neither alphabetical nor reverse
        // alphabetical, so a registry that returned them as written would fail.
        foreach (['ZebraSeeder', 'AlphaSeeder', 'MiddleSeeder'] as $class) {
            $this->writeSeeder($class);
        }

        $this->assertSame(
            [
                $this->fixtureName('AlphaSeeder'),
                $this->fixtureName('MiddleSeeder'),
                $this->fixtureName('ZebraSeeder'),
            ],
            $this->registry()->names()
        );
    }

    /**
     * A file that declares a class named something other than its basename is
     * skipped, not fatal.
     *
     * The registry names the class from the filename and then checks it exists.
     * Without that check the `require_once` would succeed and the subsequent
     * `resolve()` would fail with a confusing "class not found" for a name the
     * developer never wrote.
     */
    public function test_a_file_whose_class_does_not_match_its_name_is_skipped(): void
    {
        $this->writeSeeder('LeadSeeder');

        $other = 'SomethingElse' . ucfirst($this->suffix);

        $this->writeFile('WronglyNamed.php', <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Database\\Seeders;

            use Framework\\Database\\Seeding\\Seeder;

            final class {$other} extends Seeder
            {
                public function run(): void {}
            }
            PHP);

        $this->assertSame([$this->fixtureName('LeadSeeder')], $this->registry()->names());
    }

    /**
     * A PHP file in the directory that is not a seeder at all — a helper class, a
     * data file returning an array — is ignored rather than being resolved and
     * crashing on a missing `run()`.
     */
    public function test_a_php_file_that_is_not_a_seeder_is_ignored(): void
    {
        $this->writeSeeder('LeadSeeder');

        $helper = 'Fixtures' . ucfirst($this->suffix);

        $this->writeFile('Fixtures.php', <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Database\\Seeders;

            final class {$helper}
            {
                public function rows(): array
                {
                    return [];
                }
            }
            PHP);

        $this->assertSame([$this->fixtureName('LeadSeeder')], $this->registry()->names());
    }

    public function test_is_empty_reports_a_directory_with_no_seeders(): void
    {
        $this->assertTrue($this->registry()->isEmpty());
        $this->assertSame([], $this->registry()->names());

        $this->writeSeeder('LeadSeeder');

        $this->assertFalse($this->registry()->isEmpty());
    }

    /**
     * A missing directory is refused rather than read as "no seeders".
     *
     * The two mean different things: an empty directory is a project that has not
     * written a seeder yet, and a missing one is a misconfiguration — a rebranded
     * project whose path was not moved, a checkout that lost the directory. Only
     * the second is a bug, which is why `db:seed` says so rather than reporting
     * "nothing to seed".
     */
    public function test_a_missing_directory_is_refused(): void
    {
        $registry = new SeederRegistry($this->container, $this->directory . '/nope');

        $this->assertStringContainsString(
            'does not exist',
            $this->captureException(static fn () => $registry->classes())
        );
    }

    // ------------------------------------------------------------ container

    /**
     * Constructor dependencies arrive, which is what lets a seeder write through
     * the application's own repository rather than one it builds for itself.
     */
    public function test_a_seeder_receives_its_dependencies_from_the_container(): void
    {
        $marker = 'wpml_seeder_saw_' . $this->suffix;

        $this->writeSeeder('InjectedSeeder', <<<PHP
            public function run(): void
            {
                \$GLOBALS['{$marker}'] = \$this->dependency->value;
            }
            PHP, constructor: <<<'PHP'
            public function __construct(private readonly \Tests\Unit\Database\Seeding\SeededDependency $dependency)
            {
            }
            PHP);

        $this->container->instance(SeededDependency::class, new SeededDependency('from the container'));

        $this->registry()->find($this->fixtureName('InjectedSeeder'))?->run();

        $this->assertSame('from the container', $GLOBALS[$marker] ?? null);
    }

    /**
     * Building the name map does not run any seeder.
     *
     * `db:seed --class=lead` must not execute every seeder in the directory on its
     * way to selecting one. The map is built by *resolving* each instance — which
     * runs constructors, and the registry's docblock admits to that cost — but
     * `run()` is only ever called by the command.
     *
     * The counters are `$GLOBALS` rather than statics on the fixture classes,
     * because the fixture class name is regenerated per test and a static read
     * through a variable class name would be a second thing to get wrong.
     */
    public function test_building_the_name_map_does_not_run_any_seeder(): void
    {
        $marker = 'wpml_seeder_ran_' . $this->suffix;

        foreach (['AlphaSeeder', 'BetaSeeder'] as $class) {
            $this->writeSeeder($class, <<<PHP
                public function run(): void
                {
                    \$GLOBALS['{$marker}'] = (\$GLOBALS['{$marker}'] ?? 0) + 1;
                }
                PHP);
        }

        $registry = $this->registry();

        $this->assertCount(2, $registry->names());
        $this->assertArrayNotHasKey($marker, $GLOBALS);

        $registry->find($this->fixtureName('AlphaSeeder'))?->run();

        $this->assertSame(1, $GLOBALS[$marker] ?? 0);
    }

    /**
     * Two seeders claiming one name is refused at discovery.
     *
     * The alternative — the later one winning — makes `--class=lead` a command
     * whose result depends on directory read order, with nothing to say which
     * seeder ran and no record left behind.
     *
     * Both fixtures rename themselves to the same string, because that is the only
     * way two seeders in one directory can collide: the default `getName()` is
     * derived from a class name that is already unique per file.
     */
    public function test_two_seeders_sharing_a_name_are_refused(): void
    {
        $shared = 'lead-' . $this->suffix;

        $rename = <<<PHP
            public function getName(): string
            {
                return '{$shared}';
            }

            public function run(): void {}
            PHP;

        $this->writeSeeder('LeadSeeder', $rename);
        $this->writeSeeder('AnotherLeadSeeder', $rename);

        $message = $this->captureException(fn () => $this->registry()->all());

        $this->assertStringContainsString(sprintf('Two seeders are both named "%s"', $shared), $message);
        // Both classes are named, so the developer does not have to find the
        // second one by reading the directory.
        $this->assertStringContainsString($this->fixture('AnotherLeadSeeder'), $message);
    }

    // ---------------------------------------------------------------- shape

    public function test_the_path_is_the_one_it_was_given(): void
    {
        $this->assertSame($this->directory, $this->registry()->path());
    }

    /**
     * The namespace seeders are resolved under comes from config, so a rebranded
     * project's seeders resolve under its own root rather than this framework's
     * default.
     *
     * ## How the config is changed
     *
     * `Config` has **no setter** — deliberately, because a mutable global config
     * is how a suite acquires an order dependency — so the only supported way to
     * put a different value in front of the code is to `load()` a fixture
     * directory. {@see \Tests\Fixtures\seeder-config\app.php} carries
     * `'namespace' => 'Rebranded'`, which is not the library default (`App`) and
     * appears nowhere else in the suite.
     *
     * That distinction is the whole test: an assertion satisfied by `'App'` would
     * pass whether the registry read config or hardcoded the literal, because the
     * shipped default and the value would be the same string. This is the same
     * trap the sibling config fixture's docblock records.
     *
     * `finally` restores the project's own config, because `Config` is static and
     * everything after this test in the same process (and in the same PHPUnit
     * run) would otherwise see the fixture's values.
     */
    public function test_the_namespace_is_read_from_config(): void
    {
        $class = $this->fixture('RebrandedSeeder');

        Config::flush();
        Config::load(__DIR__ . '/../../../Fixtures/seeder-config');

        try {
            $this->assertSame('Rebranded', config('app.namespace'), 'The fixture config did not load.');

            $this->writeFile($class . '.php', <<<PHP
                <?php
                declare(strict_types=1);

                namespace Rebranded\\Database\\Seeders;

                use Framework\\Database\\Seeding\\Seeder;

                final class {$class} extends Seeder
                {
                    public function run(): void {}
                }
                PHP);

            $this->assertSame([$this->fixtureName('RebrandedSeeder')], $this->registry()->names());
        } finally {
            Config::flush();
            Config::load(dirname(__DIR__, 3) . '/config');
        }
    }

    // -------------------------------------------------------------- helpers

    private function registry(): SeederRegistry
    {
        return new SeederRegistry($this->container, $this->directory);
    }

    /**
     * A fixture class name, unique to this test.
     *
     * `$base` is the conventional name the test is *about* — `LeadSeeder` — and
     * the random suffix is spliced in **before** the `Seeder` suffix rather than
     * appended after it. That placement is load-bearing: appending would produce
     * `LeadSeederAbc123`, which does not end in `Seeder`, so
     * {@see \Framework\Database\Seeding\Seeder::getName()}'s suffix strip would
     * not fire and the selectable name would become `leadseederabc123` instead of
     * `leadabc123`. Splicing inside keeps the conventional spelling intact, so the
     * fixture exercises the same derivation a real seeder does.
     *
     * The suffix exists solely to keep two tests from declaring the same class —
     * see the class docblock for why that is a fatal rather than untidiness.
     */
    private function fixture(string $base): string
    {
        if (!str_ends_with($base, 'Seeder') || $base === 'Seeder') {
            return $base . ucfirst($this->suffix);
        }

        return substr($base, 0, -strlen('Seeder')) . ucfirst($this->suffix) . 'Seeder';
    }

    /**
     * The selectable name a fixture class registers under.
     *
     * The `Seeder` suffix is stripped and the rest lower-cased, exactly as
     * {@see \Framework\Database\Seeding\Seeder::getName()} does it — a
     * re-implementation on purpose, so the assertion states the *rule* rather
     * than delegating to the code under test.
     */
    private function fixtureName(string $base): string
    {
        $declared = $this->fixture($base);

        $short = str_ends_with($declared, 'Seeder') && $declared !== 'Seeder'
            ? substr($declared, 0, -strlen('Seeder'))
            : $declared;

        return strtolower($short);
    }

    /**
     * Write a seeder class into the workspace directory.
     *
     * The class is declared in the namespace the registry derives from
     * `config('app.namespace')`, which is left at its `App` default — so the
     * fixture has to spell `App\Database\Seeders` for discovery to find it,
     * exactly as a real project file would.
     *
     * ## Why a `run()` is supplied when the caller does not write one
     *
     * A fixture that overrode only `getName()` would otherwise be an abstract
     * class: `run()` is the interface's other method, and leaving it unimplemented
     * is a fatal at `require` time — "contains 1 abstract method and must
     * therefore be declared abstract". Several tests here are about the name and
     * have no opinion about `run()`, so an empty one is written for them.
     *
     * It is written *instead of* the body, not alongside it: a fixture that
     * supplies its own `run()` must not be given a second declaration, which PHP
     * refuses with "Cannot redeclare".
     *
     * ## Why the file name carries the suffix too
     *
     * {@see SeederRegistry} derives the class name from the **filename**, not from
     * the file's contents — one file, one class, named for the file, the same rule
     * migrations follow. A fixture that wrote class `LeadSeederAbc1` into
     * `LeadSeeder.php` would be looked up as `App\Database\Seeders\LeadSeeder`,
     * which does not exist, and the file would be quietly skipped: discovery
     * returning `[]` with no error at all.
     *
     * That is worth stating because it is exactly the failure this test file was
     * first written with — every discovery test failed with "expected one name,
     * got none", and the cause was the fixture rather than the registry. Naming
     * both ends with the suffix also means these fixtures exercise the real
     * convention rather than a convenience the registry does not offer.
     *
     * `$constructor` is spliced in ahead of the body for the injection test.
     */
    private function writeSeeder(string $className, string $body = '', string $constructor = ''): void
    {
        if ($body === '') {
            $body = 'public function run(): void {}';
        }

        $declared = $this->fixture($className);

        $this->writeFile($declared . '.php', <<<PHP
            <?php
            declare(strict_types=1);

            namespace App\\Database\\Seeders;

            use Framework\\Database\\Seeding\\Seeder;

            final class {$declared} extends Seeder
            {
                {$constructor}

                {$body}
            }
            PHP);
    }

    private function writeFile(string $name, string $contents): void
    {
        file_put_contents($this->directory . '/' . $name, $this->dedent($contents));
    }

    /**
     * Strip the common leading indentation from a heredoc.
     *
     * The fixtures above are indented to read well inside their test methods and
     * PHP does not care — but a fixture written with its class body at column
     * twelve is harder to debug when one of these tests fails, and the file is
     * meant to be readable when it is.
     */
    private function dedent(string $contents): string
    {
        $lines = preg_split('/\R/', $contents) ?: [];
        $indents = [];

        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $indents[] = strlen($line) - strlen(ltrim($line, ' '));
            }
        }

        $strip = $indents === [] ? 0 : min($indents);

        return implode("\n", array_map(
            static fn (string $line): string => trim($line) === '' ? '' : substr($line, $strip),
            $lines
        ));
    }

    /**
     * The message thrown by a callable, or a failure if it did not throw.
     *
     * `expectException()` cannot be used here: the exception has to be caught so
     * its *message* can be asserted, and a test that both expects an exception and
     * reads its message has to do the catching itself.
     */
    private function captureException(callable $call): string
    {
        try {
            $call();
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        $this->fail('Expected a RuntimeException, and none was thrown.');
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
 * A stand-in dependency, so the injection test has something to inject that is
 * not a framework class.
 */
final class SeededDependency
{
    public function __construct(public readonly string $value)
    {
    }
}
