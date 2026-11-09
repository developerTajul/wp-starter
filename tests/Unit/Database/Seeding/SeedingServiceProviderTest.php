<?php
declare(strict_types=1);

namespace Tests\Unit\Database\Seeding;

use App\Database\Seeders\DatabaseSeeder;
use App\Database\Seeders\LeadSeeder;
use App\Providers\RepositoryServiceProvider;
use DevLinkStudios\WPServiceContainer\Container;
use Framework\Config\Config;
use Framework\Database\Commands\DbSeedCommand;
use Framework\Database\QueryServiceProvider;
use Framework\Database\Seeding\SeederRegistry;
use Framework\Database\SeedingServiceProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

/**
 * The seeding provider wired up, resolving against the project's real seeders.
 *
 * ## Why this is not just another registry test
 *
 * {@see SeederRegistryTest} hand-builds a registry over a fixture directory, so it
 * proves the registry *works*. It cannot prove that the registry the application
 * actually resolves points at the right place, or that the seeders which shipped
 * are discoverable by the names `--class=` takes. Those are the failures this file
 * exists for:
 *
 *  - the path resolving somewhere other than `database/seeders`, which makes
 *    `db:seed` report an empty project on a checkout that has two seeders in it;
 *  - a filename and class name that disagree — discovery skips such a file in
 *    silence, which is the "my new seeder does nothing" failure, found here rather
 *    than by a developer watching `db:seed` announce success;
 *  - `LeadSeeder`'s constructor dependency not resolving, which turns `db:seed`
 *    into a container stack trace.
 *
 * ## Why the project's own directory is used rather than a fixture
 *
 * A fixture would exercise the same code and prove nothing about the shipped
 * files. The claim under test is that `database/seeders/` *as committed* is
 * discoverable and its classes constructible, which only the real directory can
 * show.
 *
 * ## The global container is bound, because `base_path()` reads it
 *
 * `base_path()` throws when `path.base` is unbound, and nothing binds it under
 * PHPUnit — the entry file does that at runtime. It resolves through the global
 * {@see app()} container specifically, *not* through whichever container the
 * provider was constructed with, so `setUp()` binds `path.base` there and
 * `tearDown()` restores what was there before, the pattern
 * {@see MakeControllerCommandTest} uses.
 *
 * Handing the provider a private container would not help: the provider calls
 * the `base_path()` *function*, and that function has no way to see a container
 * other than the global one. Asserting against a private container while the
 * path came from the global one would be comparing two different worlds.
 *
 * `LeadSeeder` is constructed but never run: `run()` writes to a database this
 * test does not have. What is asserted is that the container *can build it*,
 * which is what `db:seed` needs before it decides to.
 */
final class SeedingServiceProviderTest extends TestCase
{
    private Container $container;
    private ?string $originalBase = null;
    private string $pluginRoot;

    protected function setUp(): void
    {
        WpStub::reset();

        // Four levels up from this file: tests/Unit/Database/Seeding → plugin root.
        $this->pluginRoot = dirname(__DIR__, 4);

        $this->originalBase = app()->bound('path.base') ? (string) app('path.base') : null;
        app()->instance('path.base', $this->pluginRoot);

        $this->container = app();
    }

    protected function tearDown(): void
    {
        if ($this->originalBase !== null) {
            app()->instance('path.base', $this->originalBase);
        } else {
            app()->forget('path.base');
        }
    }

    public function test_the_provider_binds_the_registry_at_the_projects_seeders_directory(): void
    {
        $this->register();

        $registry = $this->container->resolve(SeederRegistry::class);

        // Compared with the separators normalised on *both* sides. `base_path()`
        // joins with `/`, and a literal expectation built from DIRECTORY_SEPARATOR
        // would be asserting about Windows rather than about where the path
        // points. What the registry does with the string is glob it; what this
        // test cares about is which directory it names.
        $this->assertSame(
            $this->normalize($this->pluginRoot . '/database/seeders'),
            $this->normalize($registry->path()),
            'The registry must point at the project seeders directory, or db:seed finds nothing.'
        );

        // And the directory has to be the one holding this project's seeders —
        // not this plugin's copy under `framework/`, and not a `vendor/` install.
        // A path can be spelled correctly and still point at the wrong tree.
        $this->assertStringEndsWith('wp-mini-laravel/database/seeders', $this->normalize($registry->path()));
        $this->assertDirectoryExists($registry->path());
    }

    /**
     * The shipped seeders are discovered, under the names `--class=` accepts.
     *
     * Asserted by name rather than by class, because the name *is* the interface:
     * `DatabaseSeeder` has to be selectable as `database` for the root-seeder
     * convention in {@see DbSeedCommand::select()} to fire, and a rename that
     * broke that would leave the class perfectly loadable while `db:seed` quietly
     * ran every seeder instead of just the root one.
     */
    public function test_the_shipped_seeders_are_discovered_under_their_names(): void
    {
        $this->register();

        $names = $this->container->resolve(SeederRegistry::class)->names();

        $this->assertContains('database', $names, 'The root seeder must be selectable as "database".');
        $this->assertContains('lead', $names, 'LeadSeeder must be selectable as "lead".');
    }

    /**
     * Discovery resolves the classes this project actually declares.
     *
     * The name assertions above already imply *a* class was declared for each
     * file — the registry skips a file whose derived class does not exist. They do
     * not imply it was the class this test has in mind, which is what these
     * `assertInstanceOf` calls add: a `LeadSeeder.php` that declared some other
     * seeder would satisfy the names and fail here.
     */
    public function test_the_discovered_seeders_are_the_classes_that_shipped(): void
    {
        $this->register();

        $registry = $this->container->resolve(SeederRegistry::class);

        $this->assertInstanceOf(DatabaseSeeder::class, $registry->find('database'));
        $this->assertInstanceOf(LeadSeeder::class, $registry->find('lead'));
    }

    /**
     * A seeder declaring constructor dependencies is buildable — which is the
     * whole reason the registry resolves rather than instantiates.
     *
     * `LeadSeeder` takes a `SqlLeadRepository`, which takes the query builder and
     * the mapper. If any of those bindings is missing, this fails here with an
     * error naming the class — a far better failure than the same error surfacing
     * when an operator runs `db:seed` against a real database.
     */
    public function test_a_seeder_declaring_dependencies_is_resolvable(): void
    {
        $this->register();

        $seeder = $this->container->resolve(SeederRegistry::class)->find('lead');

        $this->assertInstanceOf(LeadSeeder::class, $seeder);
    }

    /**
     * `db:seed` resolves out of the container with the registry already injected.
     *
     * This is the binding WP-CLI registration goes through, so its absence is not
     * a `db:seed` that fails when run — it is a `db:seed` that cannot be
     * registered at all, which is the quieter and worse version of the same
     * problem.
     */
    public function test_the_seed_command_resolves_with_the_registry(): void
    {
        $this->register();

        $command = $this->container->resolve(DbSeedCommand::class);

        $this->assertSame(
            config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' db:seed',
            $command->getName()
        );
    }

    private function register(): void
    {
        $this->registerApplicationProviders();

        (new SeedingServiceProvider($this->container))->register();
    }

    /**
     * A path with one separator, for comparisons that are about the directory
     * named rather than about the platform that spelled it.
     */
    private function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Register the providers the seeding provider composes with, before it.
     *
     * Every test calls this, not just the one about constructor dependencies,
     * because {@see SeederRegistry::all()} resolves *every* seeder to read its
     * name — `LeadSeeder` is built on any call that lists or finds seeders, not
     * only on a call that asks for it. So the query builder binding is a
     * precondition for discovery itself, and a test that skipped it would fail
     * with a container error while appearing to be about naming.
     *
     * ## Why the list is written out rather than read from config
     *
     * Reading `config('app.providers')` is the obvious move and it does not work:
     * configuration is not loaded under PHPUnit — {@see \Framework\Config\Config}
     * has only `load()` and `flush()`, and nothing calls either in the bootstrap —
     * so the key returns its default and the loop would register nothing while
     * looking like it had. The failure that produces is a `BindingResolutionException`
     * naming the query builder, which reads as a missing framework binding rather
     * than as a test that silently iterated an empty array.
     *
     * So the two providers `LeadSeeder`'s graph actually needs are named. They are
     * also the two the seeding provider depends on in production, and this test
     * proves they compose — which is the claim. A provider that later joins the
     * shipping list without joining this one would not be caught here, and that
     * is the honest limit of the approach rather than something the config read
     * was buying back.
     */
    private function registerApplicationProviders(): void
    {
        (new RepositoryServiceProvider($this->container))->register();
        (new QueryServiceProvider($this->container))->register();
    }
}
