<?php
declare(strict_types=1);

namespace Framework\Database\Commands;

use Framework\Config\Config;
use Framework\Console\Command;
use Framework\Database\Exceptions\MigrationException;
use Framework\Database\Migrator;
use Framework\Database\Schema\Schema;

/**
 * `wp wp_mini migrate:rollback` — reverse the most recent batch.
 *
 * `--batch=<n>` targets a specific batch instead, which is the recovery path for
 * a batch that failed partway: its number is visible in `migrate:status`.
 *
 * ## Why `--pretend` matters most here
 *
 * This is the command an operator reaches for when something has already gone
 * wrong, and the one whose blast radius is hardest to predict — "the last batch"
 * is however many migrations happened to be in it. `migrate` has had a dry run
 * since it was written; leaving it off the recovery path meant the one command
 * you would most want to rehearse was the one you could not.
 */
class MigrateRollbackCommand extends Command
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function getName(): string
    {
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' migrate:rollback';
    }

    public function getDescription(): string
    {
        return 'Roll back the last batch of database migrations.';
    }

    public function handle(array $args, array $assocArgs): void
    {
        $pretend = $this->flagIsTrue($assocArgs, 'pretend');

        if ($pretend) {
            Schema::pretend();
        }

        try {
            $reverted = $this->revert($assocArgs);
        } catch (MigrationException $e) {
            $this->error($e->getMessage());

            return;
        } finally {
            // Restored on every path, including the early return above. A leaked
            // pretend adapter would turn every *later* command in the same
            // process into a dry run — and this command is often followed
            // immediately by the real one, so the operator would be told the
            // schema had been rolled back when nothing had moved.
            if ($pretend) {
                $this->reportPretend(Schema::stopPretending());
            }
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
     * The same contract `migrate --pretend` documents: only statements routed
     * through the schema builder are recorded, and a migration issuing raw
     * `$wpdb` calls has those neither recorded nor run.
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

    /**
     * @param array<string, string> $assocArgs
     * @return list<string>
     */
    private function revert(array $assocArgs): array
    {
        $progress = function (string $identifier): void {
            $this->info('  Rolling back: ' . $identifier);
        };

        if (!isset($assocArgs['batch'])) {
            return $this->migrator->rollback($progress);
        }

        // Validated rather than cast: `--batch=all` or `--batch=` would otherwise
        // become 0 and silently target a batch that cannot exist, reporting
        // "nothing to roll back" for a command the operator thinks succeeded.
        $raw = (string) $assocArgs['batch'];

        if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < 1) {
            throw new MigrationException(sprintf(
                '[%s] is not a valid batch number. Pass a positive integer, e.g. --batch=2.',
                $raw
            ));
        }

        return $this->migrator->rollbackBatch((int) $raw, $progress);
    }
}
