<?php
declare(strict_types=1);

namespace Tests\Integration\Database;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Config\Config;
use Framework\Database\Commands\DbSeedCommand;
use Framework\Database\Seeding\Seeder;
use Framework\Database\Seeding\SeederRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FakeWpdb;
use Tests\Support\WpStub;

/**
 * `db:seed`, driven directly against a real directory of seeder files.
 *
 * The command is WP-CLI-shaped but not WP-CLI-dependent: it takes the registry by
 * constructor injection and reports through the base `Command`'s helpers, which
 * are no-ops when `\WP_CLI` is absent. So the suite runs the real command class
 * and reads its messages off the subclass below — a command whose output said
 * "Seeded 3 seeder(s)" while running none would fail here.
 *
 * ## What this covers that {@see \Tests\Unit\Database\Seeding\SeederRegistryTest} does not
 *
 * That file is about discovery. This one is about *policy*: which seeder a run
 * selects, what happens when one throws, and what the three ways of matching
 * nothing each say. Those are decisions the command makes, and they are the ones
 * an operator meets.
 *
 * ## The rows are real
 *
 * The happy-path test writes through the actual WordPress option table rather
 * than recording a call, so "the seeder ran" is asserted as "the row is in the
 * database". A seeder that resolved, was selected, and then did nothing would
 * pass a call-count assertion and fail this.
 */
final class DbSeedCommandTest extends TestCase
{
    /** The options table, which is the one table the stubs always provide. */
    private const TABLE = 'wp_options';

    private string $directory;
    private Container $container;
    private string $suffix;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->suffix = bin2hex(random_bytes(4));
        $this->directory = sys_get_temp_dir() . '/wpml-dbseed-' . bin2hex(random_bytes(6));

        mkdir($this->directory, 0755, true);

        $this->container = new Container();

        // The fixtures below declare classes in a namespace of their own, so a
        // temp file called `DatabaseSeeder.php` cannot collide with the project's
        // real one — which this suite *also* loads, via
        // `SeedingServiceProviderTest`. Both scenarios are legitimate and the
        // collision between them is a fatal, so the collision is removed at its
        // root rather than by ordering the tests.
        //
        // This also means the namespace is genuinely read from config here rather
        // than hardcoded: a registry that ignored the key would look for
        // `App\Database\Seeders\...`, find nothing, and every test below would
        // report an empty project. See the fixture's docblock.
        Config::flush();
        Config::load(__DIR__ . '/../../Fixtures/seeder-integration-config');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);

        // Restored to the project's own config rather than left flushed: a later
        // integration test that reads `app.namespace` — or the `prefix` this
        // fixture overrides — would otherwise silently see this test's values.
        //
        // The restore is *not* what makes the failure above obvious, and that is
        // worth knowing: `Config::load()` no-ops on a directory that does not
        // exist, so a wrong path here leaves the config empty and every test fails
        // with "no seeders found" rather than with anything about config. Both
        // paths in this file were wrong when the fixture was introduced, and the
        // symptom named the seeder directory.
        Config::flush();
        Config::load(__DIR__ . '/../../../config');
    }

    // --------------------------------------------------------------- running

    /**
     * With a `DatabaseSeeder` present, that is what runs — not every seeder.
     *
     * This is the load-bearing selection rule. The root seeder exists to decide
     * the order, and a `db:seed` that ran every file as well would run each of
     * them twice: once through the root and once on its own.
     */
    public function test_the_root_seeder_runs_alone_when_one_exists(): void
    {
        $marker = 'wpml_seed_' . $this->suffix;

        // The root seeder must be *named* `database` for the selection rule to
        // find it — the command looks the name up, it does not pattern-match on
        // "is this the root one". So this single fixture is written with its real
        // class name, unsuffixed.
        //
        // Uniqueness comes from the *namespace* — `Integration\Database\Seeders`,
        // set by `setUp()`, not from the class name. The project declares a real
        // `App\Database\Seeders\DatabaseSeeder` and another test loads it, so a
        // second declaration under that name would be a fatal. See
        // `writeFile()` and the fixture config's docblock.
        $this->writeFile('DatabaseSeeder.php', 'DatabaseSeeder', <<<PHP
            public function run(): void
            {
                \$GLOBALS['{$marker}'] = (\$GLOBALS['{$marker}'] ?? '') . 'root;';
            }
            PHP);

        $this->writeSeeder('LeadSeeder', <<<PHP
            public function run(): void
            {
                \$GLOBALS['{$marker}'] = (\$GLOBALS['{$marker}'] ?? '') . 'lead;';
            }
            PHP);

        $command = $this->command([], $marker);

        // Only the root ran. The lead seeder is present in the directory and did
        // *not* execute on its own — which is the whole point: a run that selected
        // the root seeder *and* every file would have seeded each one twice.
        $this->assertSame('root;', $GLOBALS[$marker] ?? '');
        $this->assertStringContainsString('Seeded 1 seeder(s)', $command->output());
        $this->assertStringNotContainsString('Seeding: ' . $this->fixtureName('LeadSeeder'), $command->output());
    }

    /**
     * With no root seeder, every seeder runs — which is what makes `db:seed`
     * useful in a project that has not written one.
     */
    public function test_every_seeder_runs_when_there_is_no_root_seeder(): void
    {
        $marker = $this->marker();

        foreach (['AlphaSeeder', 'BetaSeeder'] as $class) {
            $this->writeSeeder($class);
        }

        $command = $this->command([], $marker);

        $this->assertSame(
            [$this->fixtureName('AlphaSeeder'), $this->fixtureName('BetaSeeder')],
            $this->ran($marker)
        );
        $this->assertStringContainsString('Seeded 2 seeder(s)', $command->output());
    }

    /**
     * `--class=` selects exactly one seeder, by name, and it is the only one that
     * runs.
     *
     * The name is the derived one — `beta<suffix>`, not `BetaSeeder<suffix>` — so
     * this pins that the generator's hint and the command's matcher agree.
     */
    public function test_class_selects_a_single_seeder_by_name(): void
    {
        $marker = $this->marker();

        foreach (['AlphaSeeder', 'BetaSeeder'] as $class) {
            $this->writeSeeder($class);
        }

        $name = $this->fixtureName('BetaSeeder');
        $command = $this->command(['class' => $name], $marker);

        $this->assertSame([$name], $this->ran($marker));
        $this->assertStringContainsString('Seeded 1 seeder(s)', $command->output());
        $this->assertStringContainsString('Seeding: ' . $name, $command->output());
    }

    /**
     * `--class=` is case-insensitive, matching the registry's folding — a
     * developer typing a class-ish name is not making a distinction.
     */
    public function test_class_matching_folds_case(): void
    {
        $marker = 'wpml_seed_' . $this->suffix;

        $this->writeSeeder('LeadSeeder', <<<PHP
            public function run(): void
            {
                \$GLOBALS['{$marker}'] = 'ran';
            }
            PHP);

        $command = $this->command(['class' => strtoupper('Lead' . $this->suffix)], $marker);

        $this->assertSame('ran', $GLOBALS[$marker] ?? null);
        $this->assertStringContainsString('Seeded 1 seeder(s)', $command->output());
    }

    /**
     * A bare `--class` with no value is read as "not given", so the default run
     * happens rather than a lookup for a seeder named `"1"`.
     *
     * WP-CLI hands a valueless flag through as boolean `true`, and casting that to
     * a string produces `"1"` — which is a perfectly plausible seeder name and
     * would produce a bewildering "No seeder named 1".
     */
    public function test_a_bare_class_flag_falls_back_to_the_default_run(): void
    {
        $marker = 'wpml_seed_' . $this->suffix;

        $this->writeSeeder('LeadSeeder', <<<PHP
            public function run(): void
            {
                \$GLOBALS['{$marker}'] = 'ran';
            }
            PHP);

        $command = $this->command(['class' => true], $marker);

        $this->assertSame('ran', $GLOBALS[$marker] ?? null);
        $this->assertStringNotContainsString('No seeder named', $command->output());
    }

    // --------------------------------------------------------- the failures

    /**
     * A failing seeder stops the run, and the ones after it do not execute.
     *
     * Continuing would leave the database in a state that is neither the old one
     * nor the new one, with a success message at the end — and the operator's next
     * move would be made against a database they believe is seeded and is not.
     */
    public function test_a_failing_seeder_stops_the_run_and_the_rest_do_not_run(): void
    {
        $marker = 'wpml_seed_' . $this->suffix;

        // Names chosen so the failing one is *first*: seeders run in sorted order,
        // so `alpha…` precedes `beta…`.
        $this->writeSeeder('AlphaSeeder', <<<'PHP'
            public function run(): void
            {
                throw new \RuntimeException('the database said no');
            }
            PHP);

        $this->writeSeeder('BetaSeeder', <<<PHP
            public function run(): void
            {
                \$GLOBALS['{$marker}'] = 'beta ran';
            }
            PHP);

        $command = $this->command([], $marker);

        $this->assertArrayNotHasKey($marker, $GLOBALS, 'The seeder after the failing one ran.');

        $output = $command->output();

        // The seeder that failed is named: WP-CLI prints a stack trace for an
        // escaping exception, and a stack trace's first line does not say *which*
        // of twelve seeders left the database half-populated.
        $this->assertStringContainsString('alpha' . $this->suffix, $output);
        $this->assertStringContainsString('the database said no', $output);
        $this->assertStringContainsString('did not run', $output);
        $this->assertStringNotContainsString('Seeded', $output);
    }

    /**
     * A failing seeder is reported as a *failure*, not as success.
     *
     * The guard against the specific bug that matters here: a run that caught the
     * exception and then fell through to the summary would print both the error
     * and "Seeded 2 seeder(s)", and an operator skimming the last line would read
     * the second.
     */
    public function test_a_failed_run_never_reports_success(): void
    {
        $this->writeSeeder('AlphaSeeder', <<<'PHP'
            public function run(): void
            {
                throw new \RuntimeException('nope');
            }
            PHP);

        $command = $this->command([], 'wpml_unused_' . $this->suffix);

        $this->assertSame([], $command->successes);
        $this->assertNotSame([], $command->errors);
    }

    /**
     * An empty directory is reported as such, and as a warning rather than an
     * error — a project with no seeders has not done anything wrong.
     */
    public function test_an_empty_directory_is_reported_as_a_warning_not_an_error(): void
    {
        $command = $this->command([], 'wpml_unused_' . $this->suffix);

        $this->assertStringContainsString('No seeders found', $command->output());
        $this->assertSame([], $command->errors);
    }

    /**
     * A `--class=` that matches nothing lists what *is* available.
     *
     * Without the list, an operator who typo'd one word has to go and read the
     * directory to find out what they meant.
     */
    public function test_an_unknown_class_lists_the_available_names(): void
    {
        $this->writeSeeder('LeadSeeder');

        $command = $this->command(['class' => 'nope'], 'wpml_unused_' . $this->suffix);

        $output = $command->output();

        $this->assertStringContainsString('No seeder named "nope"', $output);
        $this->assertStringContainsString('lead' . $this->suffix, $output);
        $this->assertArrayNotHasKey('wpml_unused_' . $this->suffix, $GLOBALS);
    }

    /**
     * A missing seeders directory is an error, and is worded differently from an
     * empty one.
     *
     * The two mean different things — nothing written yet versus a
     * misconfiguration — and telling a developer to check a spelling when the
     * directory is absent sends them looking for a typo that is not there.
     */
    public function test_a_missing_directory_is_reported_as_an_error(): void
    {
        $registry = new SeederRegistry($this->container, $this->directory . '/absent');

        $command = new TestableDbSeedCommand($registry);
        $command->handle([], []);

        $this->assertStringContainsString('does not exist', $command->output());
        $this->assertSame([], $command->successes);
    }

    /**
     * A missing root seeder named by name is refused when the project has a
     * `DatabaseSeeder` that calls it — which is the registry-level version of the
     * same failure, exercised through `--class=`.
     */
    public function test_two_seeders_sharing_a_name_make_the_run_fail_loudly(): void
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

        $command = $this->command([], 'wpml_unused_' . $this->suffix);

        $this->assertStringContainsString('Two seeders are both named', $command->output());
        $this->assertSame([], $command->successes);
    }

    // ------------------------------------------------------------- the rows

    /**
     * A seeder that writes a row really writes it.
     *
     * Everything above counts executions; this asserts the effect. The seeder
     * inserts into the options table through the `$wpdb` global the base class
     * hands out, which is the path a real seeder takes when it reaches for raw
     * storage — and a `db:seed` that ran the right seeder against the wrong
     * connection would pass every test above and fail this one.
     *
     * ## Why a `FakeWpdb` is installed as the global here
     *
     * {@see Seeder::db()} reads `$GLOBALS['wpdb']` lazily on each call, and nothing
     * in this suite populates it by default — the stubs declare the `wpdb` *class*,
     * not an instance. So the test installs one, writes through it, and restores
     * the global in `finally`: a leftover fake would make the *next* test's seeder
     * silently write into this one's table.
     */
    public function test_a_seeder_that_writes_a_row_really_writes_it(): void
    {
        $key = 'wpml_seeded_' . $this->suffix;
        $db = new FakeWpdb();

        $db->createTable(self::TABLE, ['option_id', 'option_name', 'option_value', 'autoload']);

        $previous = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = $db;

        try {
            $this->writeSeeder('LeadSeeder', <<<PHP
                public function run(): void
                {
                    \$this->db()->insert(\$this->table('options'), [
                        'option_name' => '{$key}',
                        'option_value' => 'seeded',
                        'autoload' => 'yes',
                    ]);
                }
                PHP);

            $command = $this->command(['class' => $this->fixtureName('LeadSeeder')], $this->marker());

            $this->assertStringContainsString('Seeded 1 seeder(s)', $command->output());
            $this->assertSame('seeded', $this->optionValue($db, $key));
        } finally {
            if ($previous === null) {
                unset($GLOBALS['wpdb']);
            } else {
                $GLOBALS['wpdb'] = $previous;
            }
        }
    }

    /**
     * A seeder that reaches for a database handle the process does not have fails
     * with a message that names it, rather than a null dereference deeper down.
     *
     * ## Why this test asserts a failure rather than a success
     *
     * An earlier version of this test asserted the seeder ran to completion, on
     * the assumption that the WordPress stubs declare a global `$wpdb`. They do
     * not — `tests/stubs/wordpress.php` declares the `wpdb` *class* and the
     * functions, and `$GLOBALS['wpdb']` is only populated by a test that
     * constructs a {@see \Tests\Support\FakeWpdb} and installs it itself. This
     * integration test does not, so `Seeder::db()` correctly refuses.
     *
     * That refusal is the behaviour worth pinning: the message has to say which
     * seeder failed and *why*, because "WordPress is not loaded in this process"
     * is the difference between a misconfigured CLI context and a seeder bug.
     */
    public function test_a_seeder_without_a_database_handle_fails_with_a_message_that_says_so(): void
    {
        $this->writeSeeder('LeadSeeder', <<<'PHP'
            public function run(): void
            {
                $this->db();
            }
            PHP);

        $command = $this->command(['class' => $this->fixtureName('LeadSeeder')], $this->marker());

        $output = $command->output();

        $this->assertStringContainsString('there is no $wpdb', $output);
        $this->assertStringContainsString($this->fixtureName('LeadSeeder'), $output);
        // A failure, not a success — the same guard the failing-seeder test makes.
        $this->assertSame([], $command->successes);
    }

    // ------------------------------------------------------------- helpers

    /**
     * Run the command, returning it so its captured output can be read.
     *
     * @param array<string, mixed> $assocArgs
     */
    private function command(array $assocArgs, string $marker): TestableDbSeedCommand
    {
        unset($GLOBALS[$marker]);

        $command = new TestableDbSeedCommand(
            new SeederRegistry($this->container, $this->directory)
        );

        $command->handle([], $assocArgs);

        return $command;
    }

    /**
     * The seeder names that ran, read back from the marker they appended to.
     *
     * @return list<string>
     */
    private function ran(string $marker): array
    {
        $recorded = (string) ($GLOBALS[$marker] ?? '');

        return $recorded === '' ? [] : explode(';', rtrim($recorded, ';'));
    }

    private function optionValue(FakeWpdb $db, string $name): ?string
    {
        $value = $db->get_var($db->prepare(
            'SELECT option_value FROM ' . self::TABLE . ' WHERE option_name = %s',
            $name
        ));

        return $value === null ? null : (string) $value;
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

    /**
     * Write a seeder class into the workspace directory.
     *
     * Both the filename and the declared class carry the per-test suffix, because
     * {@see SeederRegistry} derives the class from the **filename** — a fixture
     * whose class and file disagreed would be skipped in silence. The suffix sits
     * *inside* the `Seeder` suffix so the derived name stays what a real project's
     * would be; see {@see \Tests\Unit\Database\Seeding\SeederRegistryTest} for the
     * full account of that.
     * The default body records the seeder's own *selectable name* into
     * {@see marker()}, so a test can assert which seeders ran — and, crucially,
     * assert it in the vocabulary the command selects by. Recording the class name
     * instead would let `test_class_selects_a_single_seeder_by_name` pass against a
     * command that ran the wrong seeder, because the two names are not the same
     * string.
     *
     * A test with a body of its own supplies it and gets no marker, which is what
     * the failure-path tests want: they assert on the *absence* of a marker, and a
     * default that wrote one would make every one of them fail for the wrong
     * reason.
     */
    private function writeSeeder(string $className, ?string $body = null): void
    {
        $declared = $this->fixture($className);

        if ($body === null) {
            $body = $this->markerCode($this->marker(), $this->fixtureName($className));
        }

        $this->writeFile($declared . '.php', $declared, $body);
    }

    /**
     * Write a seeder file naming its class explicitly.
     *
     * Split out for the one fixture whose class name cannot carry the per-test
     * suffix — `DatabaseSeeder`, which the command selects by the *name*
     * `database`. Everything else goes through {@see writeSeeder()}.
     *
     * ## Why the namespace is not `App\Database\Seeders`
     *
     * It is, in effect — `setUp()` loads a fixture config whose `app.namespace` is
     * `Integration\Database`, so the registry derives exactly the class name
     * written below. The value is *not* the literal `App` for the reason in that
     * fixture's docblock: two files declaring `App\Database\Seeders\DatabaseSeeder`
     * cannot both be loaded in one PHPUnit process, and this suite is not the only
     * one that needs a `DatabaseSeeder`.
     *
     * The namespace is spelled here as a literal rather than interpolated from
     * `config()` on purpose. If it were read from config, a registry that ignored
     * the config key would still be handed the right namespace by this helper, and
     * these tests would pass against the bug they exist to catch.
     */
    private function writeFile(string $filename, string $declared, string $body): void
    {
        file_put_contents($this->directory . '/' . $filename, <<<PHP
            <?php
            declare(strict_types=1);

            namespace Integration\\Database\\Seeders;

            use Framework\\Database\\Seeding\\Seeder;

            final class {$declared} extends Seeder
            {
                {$body}
            }
            PHP);
    }

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
     * The `Seeder` suffix stripped and the rest lower-cased, re-implemented here
     * on purpose — the assertion should state the rule rather than delegate to the
     * code under test.
     */
    private function fixtureName(string $base): string
    {
        $declared = $this->fixture($base);

        return strtolower(str_ends_with($declared, 'Seeder') && $declared !== 'Seeder'
            ? substr($declared, 0, -strlen('Seeder'))
            : $declared);
    }

    /**
     * This test's marker global.
     *
     * A global rather than a static on the fixture class, because fixture class
     * names are regenerated per test: reading a static through a computed class
     * name would be a second thing to get wrong in a test that is already
     * assembling source.
     */
    private function marker(): string
    {
        return 'wpml_seed_' . $this->suffix;
    }

    /**
     * The body of a fixture `run()` that appends a label to the marker global.
     *
     * The label is the seeder's *selectable name*, not its class name. That is
     * what makes the assertions above meaningful:
     * `test_class_selects_a_single_seeder_by_name` claims `--class=<name>` ran
     * exactly the seeder that name selects, and recording the class name instead
     * would let a command that ran the wrong seeder pass, because the two strings
     * differ.
     */
    private function markerCode(string $marker, string $label): string
    {
        return <<<PHP
            public function run(): void
            {
                \$GLOBALS['{$marker}'] = (\$GLOBALS['{$marker}'] ?? '') . '{$label};';
            }
            PHP;
    }
}

/**
 * The real command with its output captured and nothing sent to WP-CLI.
 *
 * `success`/`error`/`info`/`warning` are overridden rather than `\WP_CLI` being
 * stubbed, because the base class's helpers are already no-ops without WP-CLI —
 * so a stub would capture nothing and every assertion below would pass against an
 * empty string. Capturing at this seam is what makes "the run reported a failure"
 * a real claim.
 *
 * `successes` and `errors` are separated from the combined `messages` because the
 * most important assertion in this file is that a failed run produced *no*
 * success line — and a single concatenated log cannot tell the two apart.
 */
final class TestableDbSeedCommand extends DbSeedCommand
{
    /** @var list<string> */
    public array $messages = [];

    /** @var list<string> */
    public array $successes = [];

    /** @var list<string> */
    public array $errors = [];

    public function output(): string
    {
        return implode("\n", $this->messages);
    }

    protected function success(string $message): void
    {
        $this->messages[] = $message;
        $this->successes[] = $message;
    }

    protected function error(string $message): void
    {
        $this->messages[] = $message;
        $this->errors[] = $message;
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
