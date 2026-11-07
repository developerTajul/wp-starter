<?php
declare(strict_types=1);

namespace Framework\Database\Commands;

use Framework\Config\Config;
use Framework\Console\Command;
use Framework\Database\Exceptions\MigrationException;
use Framework\Database\Migrator;
use Framework\Database\Schema\Schema;
use Throwable;

/**
 * `wp wp_mini migrate:refresh` — reverse every batch, then migrate from scratch.
 *
 * The corrective half of `migrate:fresh`. Where `fresh` throws the schema away
 * and rebuilds it, this runs each migration's own `down()` first — so the result
 * is the same schema, reached through the path a developer wrote and can debug,
 * with any error surfaced against the migration that caused it.
 *
 * ## Why it exists when `fresh` already exists
 *
 * They fail differently, and that difference is the whole reason to keep both.
 *
 * `fresh` never calls `down()`, so it cannot tell you that a `down()` is broken —
 * it will report success over a migration whose `down()` has been failing for
 * months. `refresh` calls every one of them, so it is the command that finds out.
 * Conversely a `down()` that throws aborts `refresh` partway and leaves the
 * database in a half-reversed state, which is exactly the state `fresh` is
 * designed to be immune to.
 *
 * So: `refresh` when you want the reverse path exercised, `fresh` when you want
 * a known-good schema regardless of what the reverse path does.
 *
 * ## Why there is no `--step` or `--batch` here
 *
 * Both exist on `migrate:rollback`, and both would make this command's contract
 * ambiguous: "refresh from batch 3" does not say whether the batches before it
 * stay applied or are re-run, and every reading produces a different database.
 * Partial reversal is what `rollback --batch=<n>` is for.
 */
class MigrateRefreshCommand extends Command
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function getName(): string
    {
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' migrate:refresh';
    }

    public function getDescription(): string
    {
        return 'Roll back every migration, then run them all again.';
    }

    public function handle(array $args, array $assocArgs): void
    {
        $pretend = $this->flagIsTrue($assocArgs, 'pretend');

        if ($pretend) {
            Schema::pretend();
        }

        try {
            $this->refresh($pretend);
        } finally {
            // `refresh` touches both halves — it reverses everything and then
            // re-applies it — so a leak here would leave the schema either empty
            // or double-applied depending on which side leaked. Restored on every
            // path, including the early returns inside.
            if ($pretend) {
                $this->reportPretend(Schema::stopPretending());
            }
        }
    }

    private function refresh(bool $pretend): void
    {
        try {
            $batches = $this->migrator->batches();
        } catch (MigrationException $e) {
            $this->error($e->getMessage());

            return;
        }

        // The plan is stated before any of it runs. A refresh against a large
        // schema takes long enough that an operator wants to know what they
        // started, and the batch count is the only thing that distinguishes
        // "three migrations" from "three hundred".
        $this->info(sprintf(
            '%s %d batch(es) from %s',
            $pretend ? 'Pretending to roll back' : 'Rolling back',
            count($batches),
            $this->migrator->getMigrationPath()
        ));

        try {
            $reverted = $this->migrator->rollbackAll(function (string $identifier): void {
                $this->info('  Rolling back: ' . $identifier);
            });
        } catch (MigrationException $e) {
            // Stopped mid-sweep. What was reversed is genuinely reversed and its
            // rows are gone, so the honest report is how far it got — and the
            // instruction is to fix and re-run, not to reach for `fresh` before
            // understanding what broke.
            $this->error($e->getMessage());

            if ($e->getPrevious() instanceof Throwable) {
                $this->warning('Caused by: ' . $e->getPrevious()::class . ': ' . $e->getPrevious()->getMessage());
            }

            $this->warning(sprintf(
                'Stopped after %d migration(s). The ones already reversed stay reversed; '
                . 'fix the cause and re-run.',
                count($this->migrator->lastRunReverted())
            ));

            return;
        }

        $this->info($reverted === []
            ? 'Nothing was applied, so there was nothing to roll back.'
            : sprintf(
                $pretend ? 'Would have rolled back %d migration(s).' : 'Rolled back %d migration(s).',
                count($reverted)
            ));

        // Straight into the re-run, because the two halves are one operation: a
        // refresh that stopped after reversing would leave the schema empty and
        // the command reading as though it had succeeded.
        $this->info($pretend ? 'Pretending to re-run all migrations...' : 'Re-running all migrations...');

        try {
            $applied = $this->migrator->runPending(function (string $identifier): void {
                $this->info('  Migrating: ' . $identifier);
            });
        } catch (MigrationException $e) {
            $this->error($e->getMessage());

            if ($e->getPrevious() instanceof Throwable) {
                $this->warning('Caused by: ' . $e->getPrevious()::class . ': ' . $e->getPrevious()->getMessage());
            }

            $landed = $this->migrator->lastRunApplied();

            $this->warning(sprintf(
                'The rollback completed, but only %d migration(s) were re-applied. '
                . 'The database is partially migrated — fix the failure and run `migrate`.',
                count($landed)
            ));

            return;
        }

        $this->success($pretend
            ? sprintf('Pretended to refresh: %d would be rolled back, %d re-applied.', count($reverted), count($applied))
            : sprintf('Refreshed: %d rolled back, %d applied.', count($reverted), count($applied)));
    }

    /**
     * Print the statements a pretend run would have issued.
     *
     * @param list<string> $statements
     */
    private function reportPretend(array $statements): void
    {
        $this->info(sprintf(
            'Pretending: %d statement(s) would have been issued. Nothing was written.',
            count($statements)
        ));

        foreach ($statements as $statement) {
            $this->info('  ' . $statement);
        }
    }
}
