<?php
declare(strict_types=1);

namespace Framework\Database\Commands;

use Framework\Console\Command;
use Framework\Database\Exceptions\MigrationException;
use Framework\Database\Migrator;

/**
 * `wp wp_mini migrate:status` — report every migration and whether it has run.
 *
 * Printed as a fixed-width table because `info()` writes raw lines to the
 * terminal and WP-CLI renders them literally; building the columns here keeps the
 * output aligned without depending on WP-CLI's own table formatter, which is not
 * available when the command runs outside WP-CLI.
 */
class MigrateStatusCommand extends Command
{
    /**
     * Column widths, in the order the columns are printed.
     */
    private const WIDTH_RAN = 4;
    private const WIDTH_MIGRATION = 40;
    private const WIDTH_BATCH = 6;
    private const WIDTH_CLASS = 30;

    public function __construct(private readonly Migrator $migrator)
    {
    }

    public function getName(): string
    {
        return config('app.cli_prefix', 'wp_mini') . ' migrate:status';
    }

    public function getDescription(): string
    {
        return 'Show the status of each database migration.';
    }

    public function handle(array $args, array $assocArgs): void
    {
        try {
            $status = $this->migrator->getStatus();
            $missing = $this->migrator->missing();
        } catch (MigrationException $e) {
            $this->error($e->getMessage());

            return;
        }

        if ($status === [] && $missing === []) {
            $this->info(sprintf('No migrations found in %s.', $this->migrator->getMigrationPath()));

            return;
        }

        $this->info($this->row('Ran?', 'Migration', 'Batch', 'Class'));
        $this->info($this->rule());

        foreach ($status as $migration) {
            $this->info($this->row(
                $migration['ran'] ? 'Yes' : 'No',
                $migration['identifier'],
                $migration['ran'] ? (string) $migration['batch'] : '-',
                $migration['class']
            ));
        }

        if ($missing !== []) {
            // Reported separately and loudly: this is the one state the migrator
            // cannot repair on its own, and it blocks a rollback of any batch
            // those rows belong to.
            $this->warning(sprintf(
                '%d migration(s) are recorded as ran but their files are missing: %s',
                count($missing),
                implode(', ', $missing)
            ));
        }

        $pending = count(array_filter($status, static fn (array $m): bool => !$m['ran']));

        $this->info('');
        $this->info(sprintf(
            '%d migration(s) total, %d pending.',
            count($status),
            $pending
        ));
    }

    private function row(string $ran, string $migration, string $batch, string $class): string
    {
        // Each column is padded on its own rather than through one sprintf
        // format string: the widths are named constants, and building "%-22s" by
        // concatenation inside a format argument is how a stray `%-` ends up
        // being read as a specifier.
        return sprintf(
            '%s %s %s %s',
            str_pad($ran, self::WIDTH_RAN),
            str_pad($migration, self::WIDTH_MIGRATION),
            str_pad($batch, self::WIDTH_BATCH),
            str_pad($class, self::WIDTH_CLASS)
        );
    }

    private function rule(): string
    {
        return str_repeat(
            '-',
            self::WIDTH_RAN + self::WIDTH_MIGRATION + self::WIDTH_BATCH + self::WIDTH_CLASS + 3
        );
    }
}
