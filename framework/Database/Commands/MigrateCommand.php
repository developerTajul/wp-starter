<?php
declare(strict_types=1);

namespace Framework\Database\Commands;

use Framework\Console\Command;
use Framework\Database\Exceptions\MigrationException;
use Framework\Database\Migrator;
use Throwable;

/**
 * `wp wp_mini migrate` — apply every pending migration as one batch.
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
        return config('app.cli_prefix', 'wp_mini') . ' migrate';
    }

    public function getDescription(): string
    {
        return 'Run all pending database migrations.';
    }

    public function handle(array $args, array $assocArgs): void
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

        $this->info(sprintf('Running %d migration(s)...', count($pending)));

        // Only announces progress; the count of what actually landed is read from
        // the migrator below, because this callback fires *before* each migration
        // runs and would count the failing one as applied.
        try {
            $applied = $this->migrator->runPending(function (string $identifier): void {
                $this->info('  Migrating: ' . $identifier);
            });
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
                'Recorded %d of %d migration(s); %d stayed pending and will be retried.',
                count($applied),
                count($pending),
                $unrecorded
            ));

            // Spelled out because the failed run still consumed a batch number
            // without recording anything under it. `migrate:rollback` targets the
            // newest *recorded* batch, so it will not touch this one — an operator
            // who assumes otherwise reverses the previous batch by mistake.
            if ($applied === []) {
                $this->warning(
                    'This batch recorded nothing, so it cannot be rolled back. '
                    . 'migrate:rollback targets the previous batch instead.'
                );
            }

            return;
        }

        $this->success(sprintf('Migrated %d migration(s).', count($applied)));
    }
}
