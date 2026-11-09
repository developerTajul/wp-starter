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
 * `wp wp_mini migrate` - apply every pending migration as one batch.
 *
 * The migrator is injected rather than built here, so the command has no opinion
 * about where migrations live or which database handle is in play. That is what
 * lets a test drive this class against a temporary directory and a fake `$wpdb`
 * with no WordPress and no WP-CLI present.
 */
class MigrateCommand extends Command
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function getName(): string
    {
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' migrate';
    }

    public function getDescription(): string
    {
        return 'Run all pending database migrations.';
    }

    public function handle(array $args, array $assocArgs): void
    {
        $pretend = $this->wantsPretend($assocArgs);
        $step = $this->hasFlag($assocArgs, 'step');

        if ($pretend) {
            Schema::pretend();
        }

        try {
            $this->migrate($pretend, $step);
        } finally {
            // The listing is taken in a `finally` so a migration that throws still
            // prints what it had got through - that is the half of the output an
            // operator debugging a failing migration most wants - and so the
            // process is never left recording after `handle()` returns.
            if ($pretend) {
                $this->reportPretend(Schema::stopPretending());
            }
        }
    }

    /**
     * Whether a flag was passed, reading an explicit false as no.
     *
     * Accepts `--step` and `--step=1`, and refuses to read `false`/`0`/`no`/`off`
     * as "yes": WP-CLI hands associative arguments through as strings, and a bare
     * `--step` arrives as boolean `true`, so a plain truthiness test would
     * quietly turn `--step=false` into a stepwise run.
     *
     * The list of falsy spellings lives on the base class so it cannot drift from
     * the other commands', see {@see Command::FALSY_FLAG_VALUES}.
     */
    private function hasFlag(array $assocArgs, string $name): bool
    {
        return $this->flagIsTrue($assocArgs, $name);
    }

    private function wantsPretend(array $assocArgs): bool
    {
        return $this->hasFlag($assocArgs, 'pretend');
    }

    private function migrate(bool $pretend, bool $step): void
    {
        $this->info(sprintf('Migrations directory: %s', $this->migrator->getMigrationPath()));

        try {
            $pending = $this->migrator->pendingMigrations();
        } catch (MigrationException $e) {
            $this->error($e->getMessage());

            return;
        }

        if ($pending === []) {
            $this->success('Nothing to migrate. The database is up to date.');

            return;
        }

        $this->info($step
            ? sprintf('Running %d migration(s), one batch each...', count($pending))
            : sprintf('Running %d migration(s)...', count($pending)));

        // Only announces progress; the count of what actually landed is read from
        // the migrator below, because this callback fires *before* each migration
        // runs and would count the failing one as applied.
        try {
            $applied = $step
                ? $this->migrator->runPendingStepwise($this->progress())
                : $this->migrator->runPending($this->progress());
        } catch (MigrationException $e) {
            // The migrator records only what completed, so an accurate count here
            // is what the operator needs before deciding to fix and re-run.
            $this->error($e->getMessage());

            if ($e->getPrevious() instanceof Throwable) {
                $this->warning('Caused by: ' . $e->getPrevious()::class . ': ' . $e->getPrevious()->getMessage());
            }

            $applied = $this->migrator->lastRunApplied();
            $unrecorded = count($pending) - count($applied);

            $this->warning(sprintf(
                '%s %d of %d migration(s); %d stayed pending and will be retried.',
                $pretend ? 'Would have recorded' : 'Recorded',
                count($applied),
                count($pending),
                $unrecorded
            ));

            // Spelled out because the failed run still consumed a batch number
            // without recording anything under it. `migrate:rollback` targets the
            // newest *recorded* batch, so it will not touch this one - an operator
            // who assumes otherwise reverses the previous batch by mistake. Under
            // `--step` the earlier migrations each *did* record, so the warning is
            // about the failed one alone and is still true of it.
            if (!$pretend && $applied === []) {
                $this->warning(
                    'This batch recorded nothing, so it cannot be rolled back. '
                    . 'migrate:rollback targets the previous batch instead.'
                );
            }

            return;
        }

        $this->success($pretend
            ? sprintf('Pretended to migrate %d migration(s).', count($applied))
            : sprintf('Migrated %d migration(s).', count($applied)));
    }

    /**
     * The progress callback both run shapes stream through.
     *
     * @return callable(string): void
     */
    private function progress(): callable
    {
        return function (string $identifier): void {
            $this->info('  Migrating: ' . $identifier);
        };
    }

    /**
     * Print the statements a pretend run would have issued.
     *
     * Why this says what it cannot see
     *
     * The recording seam is the schema adapter, which is where every `Schema::`
     * call lands - but a migration that also issues raw `$wpdb->query()` calls
     * (a data migration rewriting values, say) has those calls neither recorded
     * nor run, because the framework does not route them and so has nothing to
     * intercept. The note is printed only when a migration actually ran, since
     * that is the only case where the omission is possible; saying it on an empty
     * run would train the operator to ignore it.
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

        if ($statements === []) {
            return;
        }

        $this->warning(
            'Only statements issued through the schema builder are listed. Raw $wpdb '
            . 'calls a migration makes are not recorded, and were not run.'
        );
    }
}
