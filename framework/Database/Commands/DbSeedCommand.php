<?php
declare(strict_types=1);

namespace Framework\Database\Commands;

use Framework\Config\Config;
use Framework\Console\Command;
use Framework\Database\Seeding\SeederInterface;
use Framework\Database\Seeding\SeederRegistry;
use RuntimeException;
use Throwable;

/**
 * `wp wp_mini db:seed` - run the project's seeders.
 *
 * With no arguments it runs `database` - the conventional root seeder, if the
 * project has one. With `--class=lead` it runs one named seeder. Both forms run
 * through the registry, so the two paths share their discovery, their error
 * messages and their idempotency rules; a second implementation for the single
 * case would be a second place for those to differ.
 *
 * Why this records nothing
 *
 * {@see \Framework\Database\Migrator} keeps a ledger so a migration never runs
 * twice. This does not, and the asymmetry is deliberate: a migration's job is to
 * transform a schema once, and running it twice is a corruption; a seeder's job is
 * to make some data present, and running it twice is the normal case - a
 * developer reseeding after a schema change, a staging box being rebuilt. There
 * is no "already seeded" state that means anything, so there is nothing to
 * record. Idempotency is the seeder's own responsibility, documented at
 * {@see SeederInterface}.
 *
 * Why a failure stops the run
 *
 * The first seeder that throws aborts the command and the ones after it do not
 * run. Continuing would leave the database in a state that is neither the old one
 * nor the new one, with a success message at the end - and the operator's next
 * move would be made against a database they believe is seeded and is not. The
 * error names the seeder that failed, so re-running after a fix is a matter of
 * running the command again.
 */
class DbSeedCommand extends Command
{
    /**
     * The seeder run when no `--class=` is given.
     *
     * A *name*, not a class, and resolved through the same registry - so a project
     * that renames `DatabaseSeeder` can override its `getName()` and this keeps
     * working. The convention is that the root seeder calls the others, which is
     * why it is seeded last when present: seeders are sorted by name, and
     * `database` happens to sort before `lead`, which is exactly backwards for the
     * one seeder that calls the rest.
     */
    public const DEFAULT_SEEDER = 'database';

    public function __construct(private readonly SeederRegistry $registry)
    {
    }

    public function getName(): string
    {
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' db:seed';
    }

    public function getDescription(): string
    {
        return 'Seed the database by running the project seeders.';
    }

    public function handle(array $args, array $assocArgs): void
    {
        // `--class=` is an option, not a flag: it carries a value. A bare
        // `--class` arrives as boolean true and is read as "not given" rather than
        // as the string "1", which would look for a seeder named "1".
        $requested = $this->optionValue($assocArgs, 'class');

        try {
            $this->seed($requested);
        } catch (RuntimeException $e) {
            // Expected failures - a missing directory, a name that matches
            // nothing, two seeders sharing a name - are reported as a message.
            $this->error($e->getMessage());
        }
    }

    private function seed(?string $requested): void
    {
        if ($this->registry->isEmpty()) {
            // Distinguished from a `--class=` miss on purpose. Telling a developer
            // to check their spelling when the directory is empty sends them to
            // look for a typo that is not there.
            $this->warning(sprintf('No seeders found in: %s', $this->registry->path()));

            return;
        }

        $seeders = $this->select($requested);

        if ($seeders === []) {
            return;
        }

        $this->info(sprintf('Seeding %d seeder(s)...', count($seeders)));

        foreach ($seeders as $name => $seeder) {
            $this->info('  Seeding: ' . $name);

            try {
                $seeder->run();
            } catch (Throwable $e) {
                // Caught here rather than left to propagate so the message can
                // name the seeder: WP-CLI prints a stack trace for an escaping
                // exception, and the first line of a stack trace does not say
                // *which* of twelve seeders left the database half-populated.
                $this->error(sprintf(
                    'Seeder "%s" failed: %s: %s',
                    $name,
                    $e::class,
                    $e->getMessage()
                ));
                $this->warning('Stopped. Seeders after this one did not run.');

                return;
            }
        }

        // Counted from what actually ran, not from what was selected, because a
        // failure returns above without reaching this line.
        $this->success(sprintf('Seeded %d seeder(s).', count($seeders)));
    }

    /**
     * The seeders this run should execute, keyed by name.
     *
     * @return array<string, SeederInterface>
     */
    private function select(?string $requested): array
    {
        if ($requested !== null) {
            return $this->selectOne($requested);
        }

        $all = $this->registry->all();

        // With no `--class=`, the root seeder runs - if the project has one.
        // Absent, every seeder runs individually, which is what a project with a
        // handful of independent seeders wants and what makes `db:seed` useful
        // before anyone has written a root seeder at all.
        if (!isset($all[self::DEFAULT_SEEDER])) {
            return $all;
        }

        return $this->selectOne(self::DEFAULT_SEEDER);
    }

    /**
     * @return array<string, SeederInterface>
     */
    private function selectOne(string $name): array
    {
        $seeder = $this->registry->find($name);

        if ($seeder === null) {
            // The available names are listed, because the alternative is an
            // operator who typo'd one word and now has to go and read the
            // directory to find out what they meant.
            $this->error(sprintf(
                'No seeder named "%s". Available: %s',
                $name,
                implode(', ', $this->registry->names()) ?: '(none)'
            ));

            return [];
        }

        return [strtolower(trim($name)) => $seeder];
    }
}
