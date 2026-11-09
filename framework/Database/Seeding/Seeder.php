<?php
declare(strict_types=1);

namespace Framework\Database\Seeding;

use Framework\Config\Config;
use RuntimeException;

/**
 * The base every seeder extends.
 *
 * What it provides, and what it deliberately does not
 *
 * Two things: a `getName()` derived from the class name, so a seeder only
 * overrides it when it wants a different word, and {@see db()} for the seeder
 * that needs raw statements.
 *
 * What it does **not** provide is a `run()`. An abstract `run()` is the whole
 * contract - see {@see SeederInterface} for why each seeder has to make the
 * idempotency decision itself rather than inherit one. A base class with a
 * `seed(array $rows)` helper would be a base class that made the "insert
 * unconditionally" choice for every subclass by making it the easy path.
 *
 * Why `db()` is here when repositories are the recommended route
 *
 * Seeding through a repository is the default because it means seeded rows pass
 * the same validation and mapping as real ones - a demo lead with a malformed
 * email is a bug the seeder found, and routing it through `$wpdb` directly would
 * hide that.
 *
 * But that is not always what a fixture needs. **Fake data is sometimes invalid
 * by design**: a seeder reproducing a support ticket needs the lead with the
 * blank phone number, and the row a migration left in an inconsistent state, and
 * the 300-character name that predates the limit. Forcing those through entity
 * validation would mean either weakening the validation or not seeding the case -
 * and both are worse than a raw statement in a file whose entire purpose is test
 * data. So `db()` is available, documented as the exception, and it is the
 * seeder author's call.
 */
abstract class Seeder implements SeederInterface
{
    /**
     * The name `db:seed --class=` matches.
     *
     * Derived from the class name with a `Seeder` suffix stripped and the rest
     * lower-cased: `LeadSeeder` becomes `lead`, `DatabaseSeeder` becomes
     * `database`. The same shape {@see \Framework\Console\Commands\MakeCommandCommand}
     * uses for command names, and for the same reason - two names for one thing
     * is a chance for them to disagree.
     *
     * Why the match is case-insensitive
     *
     * `--class=lead` and `--class=Lead` both select this seeder. A developer
     * typing a class-ish name at a CLI is not making a distinction, and rejecting
     * one spelling of the same word is pure friction. The registry folds case for
     * the same reason, so the two agree about what a name is.
     */
    public function getName(): string
    {
        $short = $this->shortClassName();

        if (str_ends_with($short, 'Seeder') && $short !== 'Seeder') {
            $short = substr($short, 0, -strlen('Seeder'));
        }

        return strtolower($short);
    }

    /**
     * The WordPress database handle.
     *
     * Read from the global **lazily, on each call**, rather than captured in a
     * constructor. During `register()` on a real install `$wpdb` may not exist
     * yet, and a handle captured then would be null for the process lifetime -
     * the same seam {@see \Framework\Database\Adapters\WpDatabaseAdapter} and
     * {@see \Framework\Database\Migrator} use, and for the same reason.
     *
     * @throws RuntimeException When there is no database handle, which means
     *                          WordPress is not loaded - a seeder cannot run in
     *                          that state, and the alternative is a null dereference
     *                          several frames deeper with no clue why.
     */
    protected function db(): \wpdb
    {
        global $wpdb;

        if (!$wpdb instanceof \wpdb) {
            throw new RuntimeException(sprintf(
                '%s cannot run: there is no $wpdb. Seeders write to the site database, so this '
                . 'means WordPress is not loaded in this process.',
                static::class
            ));
        }

        return $wpdb;
    }

    /**
     * The prefixed name of a table, as `$wpdb` spells it.
     *
     * `$wpdb->prefix` is read at call time rather than from config, because on a
     * multisite install the prefix is per-site and the config value would be one
     * site's. A raw statement written against a hardcoded `wp_` prefix writes to
     * the wrong blog on every site but the first - and does so silently.
     */
    protected function table(string $name): string
    {
        return $this->db()->prefix . $name;
    }

    /**
     * The CLI prefix, for a seeder that prints guidance.
     *
     * Read through config rather than hardcoded, so a rebranded project's seeders
     * tell the developer to run that project's command.
     */
    protected function cliPrefix(): string
    {
        return (string) config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX);
    }

    /**
     * The class name without its namespace.
     */
    private function shortClassName(): string
    {
        $class = static::class;
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
