<?php
declare(strict_types=1);

namespace Framework\Database\Seeding;

use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use RuntimeException;

/**
 * Finds the project's seeders and builds them.
 *
 * Discovery mirrors the migrator's, on purpose
 *
 * A seeder is a PHP file in `database/seeders/` whose basename is the class it
 * declares - the same convention {@see \Framework\Database\Migrator} applies to
 * `database/migrations/`, using the same `glob()` and the same "one file, one
 * class, named for the file" rule. Two discovery mechanisms in one project would
 * be two conventions to learn, and the first person to add a file that satisfied
 * one and not the other would find out which.
 *
 * There is one difference, and it is deliberate: **the migrator sorts by
 * timestamp and this sorts by filename.** A migration's order is meaningful ,
 * one creates the table the next alters - and the timestamp is that meaning.
 * A seeder's order is not, except for one case: {@see DatabaseSeeder} has to run
 * *after* the seeders it calls, and it is named to sort last alphabetically
 * ("database" precedes "lead", but the root seeder is invoked by name rather than
 * by position). Seeders are meant to be independent, and sorting them
 * deterministically is what makes a run reproducible rather than dependent on
 * glob()'s documented-none order.
 *
 * Why resolution goes through the container
 *
 * A seeder that needs a repository declares it as a constructor argument, and the
 * container supplies the same instance the application uses. Seeding through a
 * repository is the recommended route - see {@see Seeder} - and this is what
 * makes it possible without the seeder constructing its own dependencies, which
 * is how a seeder ends up quietly seeding a *different* database than the one the
 * application is configured for.
 *
 * What this class does not cache
 *
 * Nothing. Discovery re-reads the directory on every call, because a long-running
 * process that cached a listing would not see a seeder added between two runs -
 * and the failure would be "my new seeder does nothing", which is the kind of
 * problem that costs more time than a directory read ever will.
 */
final class SeederRegistry
{
    /**
     * @param ContainerInterface $container Resolves each seeder, so constructor
     *                                      dependencies are injected.
     * @param string             $seedersPath Absolute path to the seeders
     *                                        directory.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly string $seedersPath,
    ) {
    }

    /**
     * Every seeder in the directory, keyed by its name.
     *
     * Why this is keyed by name and not a list
     *
     * `db:seed --class=lead` has to find one seeder among many, and a list would
     * mean building every seeder - running every constructor - to select one.
     * The container resolves lazily, so keying by name means selecting a seeder
     * only builds that seeder.
     *
     * The name comes from the *instance*, because {@see SeederInterface::getName()}
     * is overridable and a seeder that renamed itself must be selectable by the
     * name it chose. That means every constructor runs to build this map, which is
     * the cost of honouring an overridable name - and it is paid once per
     * invocation, not per seeder run.
     *
     * @return array<string, SeederInterface> Name => seeder.
     *
     * @throws RuntimeException When two seeders report the same name.
     */
    public function all(): array
    {
        $seeders = [];

        foreach ($this->classes() as $class) {
            /** @var SeederInterface $seeder */
            $seeder = $this->container->resolve($class);
            $name = $seeder->getName();
            $key = strtolower($name);

            // Refused rather than the later one winning. Two seeders sharing a
            // name is a command whose result depends on directory read order, so
            // `--class=lead` would sometimes run one and sometimes the other with
            // nothing to say which - and the run that seeded the wrong thing
            // leaves no record.
            if (isset($seeders[$key])) {
                throw new RuntimeException(sprintf(
                    'Two seeders are both named "%s": %s and %s. A name has to select one seeder, '
                    . 'so names must be unique, override getName() on one of them.',
                    $name,
                    $seeders[$key]::class,
                    $class
                ));
            }

            $seeders[$key] = $seeder;
        }

        // Sorted by name so a full run is reproducible. `ksort` is stable for the
        // string keys here, and matches the order the classes were discovered in
        // for files that share a prefix.
        ksort($seeders);

        return $seeders;
    }

    /**
     * The seeder with this name, or null.
     *
     * Case-insensitive, matching {@see Seeder::getName()}'s folding, a caller
     * typing `--class=Lead` at a CLI is not making a distinction between two
     * things.
     */
    public function find(string $name): ?SeederInterface
    {
        return $this->all()[strtolower(trim($name))] ?? null;
    }

    /**
     * The names available, sorted.
     *
     * For the message a failed `--class=` prints. Without it, an operator who
     * typo'd a name gets "no such seeder" and has to go and list the directory
     * themselves.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /**
     * Whether there are any seeders at all.
     *
     * Distinguished from an empty `all()` at the call site only by how it reads,
     * which matters: `db:seed` says something different when a project has no
     * seeders than when a `--class=` did not match, and conflating the two would
     * tell a developer to check their spelling when the directory is empty.
     */
    public function isEmpty(): bool
    {
        return $this->classes() === [];
    }

    /**
     * The absolute path seeders are discovered in.
     */
    public function path(): string
    {
        return $this->seedersPath;
    }

    /**
     * The seeder classes the directory declares.
     *
     * Why the class name is derived from the filename
     *
     * The file is `require`d and then the class is named from its basename, rather
     * than the file being scanned for its own `namespace`/`class` declarations.
     * That is what {@see \Framework\Database\MigrationFile} does and it is the
     * same trade: the convention is stated in the seeder stub and the README, a
     * file that breaks it is reported by name, and no file is tokenised to find
     * out what it claims to be.
     *
     * A `database/seeders/DatabaseSeeder.php` therefore declares
     * `App\Database\Seeders\DatabaseSeeder`, and a file that declares something
     * else is skipped - see the check below, which is what stops a class that
     * turns out not to exist from becoming a fatal at `resolve()`.
     *
     * @return list<class-string<SeederInterface>>
     *
     * @throws RuntimeException When the directory does not exist.
     */
    public function classes(): array
    {
        if (!is_dir($this->seedersPath)) {
            throw new RuntimeException(sprintf(
                'The seeders directory does not exist: %s. A project with no seeders should not '
                . 'run db:seed at all, an empty directory and a missing one mean different '
                . 'things, and only the second is a misconfiguration.',
                $this->seedersPath
            ));
        }

        $namespace = $this->namespace();
        $classes = [];

        // `glob()` documents no order, so the listing is sorted before anything
        // reads it. Without this, the order seeders run in depends on the
        // filesystem, and a seeder that happens to depend on another would work
        // on one machine and not another.
        $files = glob($this->seedersPath . '/*.php') ?: [];
        sort($files);

        foreach ($files as $path) {
            if (!is_file($path)) {
                continue;
            }

            $short = basename($path, '.php');
            $class = $namespace . '\\' . $short;

            // The file is required before the class is checked, because the
            // autoloader may not know about `database/seeders/` - it is a project
            // directory, not a PSR-4 root, exactly as migrations are.
            require_once $path;

            if (!class_exists($class)) {
                continue;
            }

            if (!is_subclass_of($class, SeederInterface::class)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * The namespace seeders are declared in.
     *
     * The application's root namespace plus `Database\Seeders`, matching where
     * {@see \Framework\Database\Commands\MakeSeederCommand} writes them. Read from
     * config so a rebranded project's seeders resolve under its own root rather
     * than this framework's default.
     */
    private function namespace(): string
    {
        $root = (string) config('app.namespace', \Framework\Config\Config::DEFAULT_NAMESPACE);

        return $root . '\\Database\\Seeders';
    }
}
