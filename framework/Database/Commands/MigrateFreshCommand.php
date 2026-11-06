<?php
declare(strict_types=1);

namespace Framework\Database\Commands;

use Framework\Config\Config;
use Framework\Console\Command;
use Framework\Database\Exceptions\MigrationException;
use Framework\Database\Migrator;
use Throwable;

/**
 * `wp wp_mini migrate:fresh` — drop everything the migrations built, then build it
 * again.
 *
 * ## The confirmation flag is required, and that is not friction
 *
 * `--force` is not a style choice. This is the only command in the framework that
 * destroys data without a reverse path, and it destroys it in the one place where
 * the wrong answer is unrecoverable: a developer with two terminals open types it
 * against production roughly once in a career, and that once is the reason the
 * flag exists. The tally below is printed in the refusal so the operator can see
 * what they were about to do rather than only that they were stopped.
 *
 * `--force` rather than an interactive prompt because WP-CLI commands are run in
 * CI and in scripts, where a prompt blocks forever. A flag works in both.
 *
 * ## What "fresh" means here
 *
 * It drops the tables **the tracked migrations' `down()` methods** remove, then
 * drops the tracking table, then migrates. It does not touch anything else in the
 * database: no `DROP DATABASE`, no sweeping of tables it did not create. A
 * WordPress install shares its database with WordPress, and a command that ran
 * `DROP DATABASE` would be a command that takes the site with it.
 *
 * ## Why `down()` is not called
 *
 * {@see Migrator::dropAllTables()} explains this at length. In short: reversing
 * is compensating logic, a fresh start is not a compensation, and going through
 * `down()` would run real destructive data operations — renaming columns back,
 * archiving rows — immediately before dropping the table that holds them. It
 * would also make the command fail whenever a `down()` was broken, which defeats
 * the point of having a command that always produces a known schema.
 *
 * Use `migrate:refresh` when you want the reverse path exercised instead.
 */
class MigrateFreshCommand extends Command
{
    /**
     * The flag that authorises destroying data.
     */
    public const FORCE_FLAG = 'force';

    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function getName(): string
    {
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' migrate:fresh';
    }

    public function getDescription(): string
    {
        return 'Drop all migration tables and re-run every migration. Requires --force.';
    }

    public function handle(array $args, array $assocArgs): void
    {
        if (!$this->isForced($assocArgs)) {
            $this->refuse();

            return;
        }

        try {
            $applied = count($this->migrator->status());
        } catch (MigrationException $e) {
            $this->error($e->getMessage());

            return;
        }

        $this->info(sprintf('Dropping tables created by %d migration(s)...', $applied));

        try {
            $dropped = $this->migrator->dropAllTables();
        } catch (MigrationException $e) {
            $this->error($e->getMessage());

            if ($e->getPrevious() instanceof Throwable) {
                $this->warning('Caused by: ' . $e->getPrevious()::class . ': ' . $e->getPrevious()->getMessage());
            }

            // Named explicitly, because `dropAllTables()` stops where it failed
            // and the remaining tables are still standing. An operator who reads
            // only "failed" will assume nothing happened and may re-run against a
            // database that is now half-empty.
            $this->warning(
                'The database is now in a partial state: tables from migrations that had already '
                . 'reversed are gone, the rest are untouched, and the tracking table was not dropped. '
                . 'Re-run `migrate:fresh --force` once the cause is fixed.'
            );

            return;
        }

        $this->info(sprintf('Dropped %d table(s).', count($dropped)));
        $this->info('Re-running all migrations...');

        try {
            $reapplied = $this->migrator->runPending(function (string $identifier): void {
                $this->info('  Migrating: ' . $identifier);
            });
        } catch (MigrationException $e) {
            $this->error($e->getMessage());

            if ($e->getPrevious() instanceof Throwable) {
                $this->warning('Caused by: ' . $e->getPrevious()::class . ': ' . $e->getPrevious()->getMessage());
            }

            $landed = $this->migrator->lastRunApplied();

            // The distinction that matters: "nothing to run" and "ran and failed"
            // look identical in a bare error message, and only one of them means
            // the database is empty. `dropAllTables()` succeeded above, so it is.
            $this->warning(sprintf(
                'Everything was dropped, but only %d migration(s) were re-applied. '
                . 'The database is empty or partial — fix the failure and run `migrate`.',
                count($landed)
            ));

            return;
        }

        $this->success(sprintf(
            'Fresh: %d table(s) dropped, %d migration(s) applied.',
            count($dropped),
            count($reapplied)
        ));
    }

    /**
     * Whether the operator authorised the destructive run.
     *
     * WP-CLI reports `--force` as the string `"1"` when written bare and as
     * `"true"`/`"yes"` when written with a value, and omits the key entirely
     * otherwise. All three are accepted; `--force=0` and `--force=false` are
     * refused, because a flag that destroys data must not be enabled by a value
     * the operator meant as "no".
     *
     * @param array<string, string> $assocArgs
     */
    private function isForced(array $assocArgs): bool
    {
        if (!array_key_exists(self::FORCE_FLAG, $assocArgs)) {
            return false;
        }

        $value = strtolower(trim((string) $assocArgs[self::FORCE_FLAG]));

        return !in_array($value, ['', '0', 'false', 'no', 'off'], true);
    }

    private function refuse(): void
    {
        $this->error(
            'migrate:fresh drops every table the migrations created and destroys their data. '
            . 'Re-run with --force if that is what you want: '
            . $this->getName() . ' --force'
        );

        // The tally is printed here rather than only inside the destructive path
        // so the operator can judge the blast radius *before* authorising it. A
        // refusal that says only "refused" gives them nothing to decide with.
        try {
            $status = $this->migrator->status();
        } catch (MigrationException) {
            return;
        }

        $ran = count(array_filter($status, static fn (array $m): bool => $m['ran']));

        $this->warning(sprintf(
            'This would drop tables from %d applied migration(s) out of %d on disk, '
            . 'and delete the migration tracking table.',
            $ran,
            count($status)
        ));
    }
}
