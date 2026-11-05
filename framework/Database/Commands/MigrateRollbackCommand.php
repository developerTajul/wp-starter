<?php
declare(strict_types=1);

namespace Framework\Database\Commands;

use Framework\Console\Command;
use Framework\Database\Exceptions\MigrationException;
use Framework\Database\Migrator;

/**
 * `wp wp_mini migrate:rollback` — reverse the most recent batch.
 *
 * `--batch=<n>` targets a specific batch instead, which is the recovery path for
 * a batch that failed partway: its number is visible in `migrate:status`.
 */
class MigrateRollbackCommand extends Command
{
    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function getName(): string
    {
        return config('app.cli_prefix', 'wp_mini') . ' migrate:rollback';
    }

    public function getDescription(): string
    {
        return 'Roll back the last batch of database migrations.';
    }

    public function handle(array $args, array $assocArgs): void
    {
        try {
            $reverted = $this->revert($assocArgs);
        } catch (MigrationException $e) {
            $this->error($e->getMessage());

            return;
        }

        if ($reverted === []) {
            $this->success('Nothing to roll back.');

            return;
        }

        $this->success(sprintf('Rolled back %d migration(s).', count($reverted)));
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
