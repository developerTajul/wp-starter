<?php
declare(strict_types=1);

namespace Framework\Database\Commands;

use Framework\Config\Config;
use Framework\Console\Command;
use Framework\Database\Adapters\DatabaseAdapterInterface;
use Framework\Database\Exceptions\MigrationException;
use Framework\Database\Schema\Exceptions\SchemaException;
use Framework\Database\Schema\Schema;
use Framework\Database\Schema\SchemaDumper;

/**
 * `wp wp_mini migrate:dump` - write the live schema back out as a migration.
 *
 * What it is for
 *
 * The migration history and the database drift apart. A column added by hand, a
 * migration that half-applied, a table an earlier developer created through
 * phpMyAdmin - none of those are in `database/migrations` and all of them are in
 * the database. This reads the catalogue and writes a migration describing what
 * is actually there, so the two can be compared and reconciled.
 *
 * Why it writes to `--output` and not to `database/migrations/`
 *
 * The product is a *snapshot*, and the usual reason to take one is to look at
 * it. A command that dropped a generated file into the migrations directory by
 * default would leave every developer who ran it once with a migration they did
 * not write and probably do not want - and running it would apply a second,
 * conflicting definition of tables the existing migrations already create. So the
 * destination is explicit, and the message says what was written where.
 *
 * The flag is `--output` rather than the more obvious `--path` because `--path`
 * is WP-CLI's own global flag for selecting a WordPress installation; see
 * {@see self::targetPath()}.
 *
 * What it does not do
 *
 * It does not reconstruct the migration history: it emits one `create` per table
 * and has no way to know which migration produced which column. It does not dump
 * data. And it reports, rather than approximates, anything it cannot
 * reconstruct - see {@see SchemaDumper}, whose limits are stated there and
 * repeated in the file it writes so a reader meets them where they matter.
 */
class MigrateDumpCommand extends Command
{
    /**
     * WordPress core tables, excluded from the default discovery.
     *
     * The default is "the project's own tables", and on a real install the
     * database prefix covers `wp_posts`, `wp_options` and forty more that belong
     * to WordPress. Dumping those would produce a migration that re-declares
     * WordPress's own schema, which is not merely useless but actively harmful,
     * since running it would assert a shape WordPress is free to change.
     *
     * A fixed list rather than a query, because the set of core tables is a fact
     * about WordPress rather than about this database, and a table added to core
     * in a future release should be a deliberate addition here rather than
     * silently swept in. Multisite's own tables are listed too: a plugin has no
     * business dumping the network schema either.
     */
    private const CORE_TABLES = [
        'posts', 'postmeta', 'options', 'users', 'usermeta', 'comments', 'commentmeta',
        'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'links',
        'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log',
        'sitecategories', 'blog_versions',
    ];

    public function __construct(private readonly SchemaDumper $dumper)
    {
    }

    public function getName(): string
    {
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' migrate:dump';
    }

    public function getDescription(): string
    {
        return 'Write the current database schema out as a migration file.';
    }

    public function handle(array $args, array $assocArgs): void
    {
        $target = $this->targetPath($assocArgs);

        if ($target === null) {
            $this->error('Please give a destination: ' . $this->getName() . ' --output=database/dumps/schema.php');

            return;
        }

        $tables = $this->tables($assocArgs);

        if ($tables === []) {
            $this->error(
                'There is nothing to dump: no tables were named and none were found under this '
                . 'database prefix. Pass --table=<name> to dump a specific one.'
            );

            return;
        }

        try {
            $source = $this->dumper->dump($tables);
        } catch (MigrationException | SchemaException $e) {
            // Both are reported and neither is thrown onward: a dumper that
            // cannot describe a column has something to say that the operator
            // needs to read, and a stack trace is not it.
            $this->error($e->getMessage());

            return;
        }

        $directory = dirname($target);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->error(sprintf('Cannot write to: %s, the directory %s could not be created.', $target, $directory));

            return;
        }

        // Checked rather than allowed to overwrite: the file may be a dump the
        // operator has since hand-edited, which is the documented workflow - dump,
        // read, adjust, keep. Silently replacing it would destroy exactly the work
        // this command exists to make possible.
        if (file_exists($target)) {
            $this->error(sprintf(
                '%s already exists. Move or delete it first, or pass a different --output: this command '
                . 'will not overwrite a file you may have edited.',
                $target
            ));

            return;
        }

        // Tested before writing rather than inferring failure from the write's
        // return value. `file_put_contents()` also returns false when the *disk*
        // is full, and the two want different advice - "fix the path" against
        // "free up space" - so the common and checkable case is separated out.
        if (!is_writable($directory)) {
            $this->error(sprintf('Cannot write to: %s, the directory %s is not writable.', $target, $directory));

            return;
        }

        if (@file_put_contents($target, $source) === false) {
            $this->error(sprintf('Cannot write to: %s', $target));

            return;
        }

        $this->success(sprintf('Dumped %d table(s) to %s', count($tables), $target));
        $this->info(
            'This is a snapshot of the schema as it is now, not a record of the migrations that '
            . 'produced it. Read it before running it.'
        );
    }

    /**
     * Where the dump is written, or null when no destination was given.
     *
     * Why the flag is `--output` and not `--path`
     *
     * `--path` is a **WP-CLI global**: `wp --path=/srv/site` selects which
     * WordPress installation to run against, and WP-CLI consumes it during
     * bootstrap, before any command sees its arguments. Declaring it as an
     * option here did not shadow the global - it lost to it. `wp wp_mini
     * migrate:dump --path=dumps/schema.php` would have had `dumps/schema.php`
     * read as a WordPress root, the command would never have received the flag,
     * and the operator would be told to "pass a different --path" while passing
     * one. An option name that the framework does not own is not a name it can
     * use.
     *
     * Not declared in a synopsis either, because this framework registers
     * commands by callable and lets WP-CLI infer the signature - so the only
     * defence is to not want the name.
     */
    private function targetPath(array $assocArgs): ?string
    {
        $path = $assocArgs['output'] ?? null;

        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        return $path;
    }

    /**
     * The tables to dump: those named, or the project's own.
     *
     * @return list<string>
     */
    private function tables(array $assocArgs): array
    {
        $named = $this->namedTables($assocArgs);

        if ($named !== []) {
            return $named;
        }

        return $this->discoverTables();
    }

    /**
     * The `--table` values, however WP-CLI spelled them.
     *
     * A repeated flag arrives as an array and a single use arrives as a string,
     * so both are accepted - the alternative is a command that works once and
     * breaks when a second table is added.
     *
     * @return list<string>
     */
    private function namedTables(array $assocArgs): array
    {
        if (!array_key_exists('table', $assocArgs)) {
            return [];
        }

        $value = $assocArgs['table'];
        $values = is_array($value) ? $value : [$value];

        $tables = [];

        foreach ($values as $table) {
            if (!is_string($table) || trim($table) === '') {
                continue;
            }

            $tables[] = trim($table);
        }

        return $tables;
    }

    /**
     * The plugin's tables, as discovered from the database.
     *
     * "The plugin's" means carrying the database prefix, minus WordPress's core
     * tables and the framework's tracking table. The prefix is the whole of the
     * plugin's claim on the database, so a table without it is not this plugin's
     * to describe - dumping it would produce a migration that fails on install,
     * since `Schema::create()` would prefix a name that is already fully spelled.
     *
     * @return list<string>
     */
    private function discoverTables(): array
    {
        try {
            $all = $this->adapter()->tables();
            $prefix = $this->adapter()->prefix();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return [];
        }

        $own = [];

        foreach ($all as $table) {
            if ($prefix !== '' && !str_starts_with($table, $prefix)) {
                continue;
            }

            $bare = $prefix === '' ? $table : substr($table, strlen($prefix));

            if (in_array($bare, self::CORE_TABLES, true)) {
                continue;
            }

            // The tracking table is refused by the dumper as well; filtered here
            // so the default discovery never even names it and the operator is
            // not shown an error for a table they did not ask for.
            if ($bare === 'plugin_migrations') {
                continue;
            }

            $own[] = $bare;
        }

        return array_values(array_unique($own));
    }

    /**
     * The adapter the command discovers tables through.
     *
     * Taken from {@see Schema} rather than resolved here, and that is the whole
     * point of the method. The command reads the schema so the dumper can
     * describe it; the `Schema::` calls a migration makes write through the same
     * seam. Resolving the interface from the container independently would give
     * the command a *different* adapter whenever `Schema::useAdapter()` has
     * substituted one - it would then happily describe a database the rest of the
     * run is not using, and nothing about the output would look wrong.
     */
    private function adapter(): DatabaseAdapterInterface
    {
        return Schema::adapter();
    }
}
