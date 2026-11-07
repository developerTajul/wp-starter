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
 * `wp wp_mini migrate:reset` — reverse every batch, and stop there.
 *
 * The first half of `migrate:refresh`, on its own. Where `rollback` undoes the
 * most recent batch, this runs each migration's `down()` for *every* batch,
 * newest first, and leaves the database empty of this plugin's schema.
 *
 * ## Why this exists when `refresh` already does it
 *
 * Because the two halves of a refresh are separately useful, and the useful one
 * is the first. A developer who wants to test their `down()` methods, or to get
 * back to a clean schema before switching branches, does not want the re-run that
 * `refresh` performs — and `rollback` only reaches one batch, so getting to empty
 * with it means running it once per batch with no idea how many that is.
 *
 * ## How it differs from `migrate:fresh`
 *
 * **`fresh` drops the tables; `reset` reverses them.** The distinction is not
 * cosmetic. `fresh` calls no `down()` at all — it is the command that works even
 * when every `down()` in the project is broken — and it also drops the tracking
 * table, resetting the batch counter. This runs the reverse path, so it is the
 * command that *finds out* whether that path works, and it leaves the tracking
 * table in place with its batch history intact.
 *
 * Neither is reversible, and neither takes a confirmation flag here — the
 * confirmation is WP-CLI's `--yes`, handled at the command layer above this one.
 *
 * ## A failure reports how far it got
 *
 * A `down()` that throws stops the sweep. The migrations already reversed are
 * genuinely reversed and their rows are gone, so re-running resumes rather than
 * repeating — the same truthful half-state `rollback` reports. Saying how many
 * succeeded is what makes that recoverable: "reset failed" alone leaves an
 * operator unable to tell an empty database from a full one.
 */
class MigrateResetCommand extends Command
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function getName(): string
    {
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' migrate:reset';
    }

    public function getDescription(): string
    {
        return 'Roll back every migration, leaving the schema empty.';
    }

    public function handle(array $args, array $assocArgs): void
    {
        $pretend = $this->flagIsTrue($assocArgs, 'pretend');

        if ($pretend) {
            Schema::pretend();
        }

        try {
            $this->reset($pretend);
        } finally {
            // Unwound on every path. A leaked pretend adapter is worse here than
            // on `rollback`: `reset` empties the schema, so an operator who ran
            // the pretend pass and then a real one in the same process would have
            // the second silently do nothing and be told it succeeded.
            if ($pretend) {
                $this->reportPretend(Schema::stopPretending());
            }
        }
    }

    private function reset(bool $pretend): void
    {
        try {
            $batches = $this->migrator->batches();
        } catch (MigrationException $e) {
            $this->error($e->getMessage());

            return;
        }

        // Stated before anything runs, for the same reason `refresh` states it:
        // a reset against a large schema takes long enough that an operator wants
        // to see what they started while they can still stop it.
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
            $this->error($e->getMessage());

            // The previous exception is the migration's own failure, and it is the
            // one that says what went wrong. The wrapper names the migration.
            if ($e->getPrevious() instanceof Throwable) {
                $this->warning('Caused by: ' . $e->getPrevious()::class . ': ' . $e->getPrevious()->getMessage());
            }

            // Read from the migrator, not from `$reverted`: the throw unwound the
            // assignment, so the local is still undefined here. The migrator
            // records as it goes for exactly this reason.
            $this->warning(sprintf(
                'Stopped after %d migration(s). The ones already reversed stay reversed; '
                . 'fix the cause and re-run.',
                count($this->migrator->lastRunReverted())
            ));

            return;
        }

        if ($reverted === []) {
            $this->success('Nothing to roll back.');

            return;
        }

        $this->success($pretend
            ? sprintf('Pretended to roll back %d migration(s).', count($reverted))
            : sprintf('Rolled back %d migration(s).', count($reverted)));
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
