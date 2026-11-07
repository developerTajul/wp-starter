<?php
declare(strict_types=1);

namespace Framework\Database\Commands;

use Framework\Config\Config;
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
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' migrate:status';
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

        if ($this->wantsPendingOnly($assocArgs)) {
            $status = array_filter($status, static fn (array $m): bool => !$m['ran']);
        }

        if ($status === [] && $missing === []) {
            // Two different situations reach this line and they must not be
            // reported the same way. With `--pending` the directory may be full of
            // migrations that have all run — the files are right there, so "no
            // migrations found" is false. Only a genuinely empty directory means
            // that.
            $this->info($this->wantsPendingOnly($assocArgs)
                ? 'Nothing pending. Every migration on disk has been applied.'
                : sprintf('No migrations found in %s.', $this->migrator->getMigrationPath()));

            return;
        }

        // Nothing left to show, but rows are missing files. The table would be
        // empty under a header, which reads as a rendering fault rather than as
        // the answer — so the answer is stated, and the missing files with it.
        if ($status === []) {
            $this->success('Nothing pending. Every migration on disk has been applied.');
            $this->reportMissing($missing);

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

        $this->reportMissing($missing);

        $pending = count(array_filter($status, static fn (array $m): bool => !$m['ran']));

        $this->info('');
        $this->info(sprintf(
            '%d migration(s) total, %d pending.',
            count($status),
            $pending
        ));
    }

    /**
     * Whether `--pending` was passed, reading an explicit false as no.
     *
     * The flag exists so a deployment script can ask "is there anything to run?"
     * without parsing a table: it narrows the listing to migrations that have not
     * run, so the count on the last line — and an empty listing — both answer that
     * question directly.
     *
     * Reads the shared falsy list on the base class, so `--pending=off` and
     * `--pending=false` mean the same thing here as they do on `--pretend`.
     */
    private function wantsPendingOnly(array $assocArgs): bool
    {
        return $this->flagIsTrue($assocArgs, 'pending');
    }

    /**
     * Report rows whose files have been deleted.
     *
     * Kept out of {@see handle()}'s flow because it is printed on every exit path
     * that reaches it, including `--pending`: a missing file is *not* pending and
     * is exactly the thing a filtered listing would otherwise hide.
     *
     * @param list<string> $missing
     */
    private function reportMissing(array $missing): void
    {
        if ($missing === []) {
            return;
        }

        // Reported separately and loudly: this is the one state the migrator
        // cannot repair on its own, and it blocks a rollback of any batch
        // those rows belong to.
        $this->warning(sprintf(
            '%d migration(s) are recorded as ran but their files are missing: %s',
            count($missing),
            implode(', ', $missing)
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
