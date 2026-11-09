<?php
declare(strict_types=1);

namespace Framework\Database\Lifecycle;

use Framework\Database\Exceptions\MigrationException;
use Framework\Database\Migrator;
use Framework\Database\Storage\MigrationStorageInterface;
use Throwable;

/**
 * Removes everything this plugin put in the database, on an explicit request.
 *
 * Why this is not `migrate:fresh`
 *
 * `Migrator::dropAllTables()` is the obvious call and the wrong one, for a
 * reason that is easy to miss because the two look identical from outside. It
 * drops the *tables*, resolving each recorded migration to a table name and
 * removing it - which means it can only remove what it can still resolve. A
 * migration whose file has been deleted from disk has a row in the ledger and no
 * `down()` to call, and `dropAllTables()` **skips it silently** (it has to: the
 * table name is not derivable from a filename alone). Uninstall is the one
 * operation where a silent skip is unacceptable - the user asked for their data
 * to be gone, and a table left behind is the promise broken in the quietest
 * possible way.
 *
 * So this runs {@see Migrator::rollbackAll()}, which reaches a recorded
 * migration with no file and *throws* naming it. That is the correct behaviour
 * here: the operator learns which file is missing rather than being told the
 * wipe succeeded.
 *
 * The second difference is the ledger. `rollbackAll()` is `migrate:reset`, and
 * reset deliberately *keeps* the tracking table and the batch high-water mark -
 * a developer reversing migrations wants to migrate again and expects the
 * numbers to continue. Uninstall is the opposite case: the plugin is going away,
 * nothing will ever read that history again, and an option row left behind is
 * leftover data as surely as a table is. Both are removed explicitly below.
 *
 * `down()` runs, and that is the point
 *
 * Every migration's `down()` executes, newest batch first. This is what makes
 * the removal *correct* rather than merely destructive: a `down()` is the
 * author's statement of how to undo their own `up()`, including the parts that
 * are not tables - a row in `wp_options`, an index, a rename. A `DROP TABLE`
 * sweep knows none of that.
 *
 * The failure contract
 *
 * A `down()` that throws stops the sweep, and the exception propagates to the
 * caller. It is deliberately *not* caught here, unlike in
 * {@see MigratesOnActivation}: an activation that fails leaves a working site
 * that will finish its schema on the next `migrate`, whereas a half-finished
 * uninstall leaves tables the user believes are gone. The caller - `uninstall.php`
 * - is where that is turned into a log line rather than a fatal, because only it
 * knows that WordPress is tearing the plugin down around it.
 *
 * The half-applied state is still recoverable and still truthful: the migrations
 * already reversed are genuinely reversed and their rows are gone, so re-running
 * resumes rather than repeating.
 */
class RemovesPluginData
{
    /**
     * @param Migrator                 $migrator The engine whose `down()`s do the removal.
     * @param MigrationStorageInterface $storage  The ledger, read here for the two
     *                                            operations the migrator has no verb for:
     *                                            dropping the tracking table and forgetting
     *                                            the batch high-water mark.
     */
    public function __construct(
        private readonly Migrator $migrator,
        private readonly MigrationStorageInterface $storage,
    ) {
    }

    /**
     * Reverse every migration and remove the migration ledger.
     *
     * @return list<string> Identifiers reversed, in execution order.
     *
     * @throws MigrationException When a `down()` fails, or when a recorded
     *                            migration's file is missing from disk.
     */
    public function __invoke(): array
    {
        $reverted = $this->migrator->rollbackAll();

        // Performed even when nothing was reversed, and that is not a wasted
        // statement. `rollbackAll()` on a database with an empty ledger does
        // nothing at all - but `ensureTrackingTable()` has already run by then
        // and *created* an empty tracking table, so a plugin uninstalled from a
        // site where it never migrated anything would otherwise leave one table
        // behind. `dropTrackingTable()` is tolerant of absence by contract, so
        // the unconditional call costs one `DROP TABLE IF EXISTS` and closes
        // that case.
        $this->storage->dropTrackingTable();

        $this->storage->forgetBatchHighWaterMark();

        return $reverted;
    }

    /**
     * The same removal, for a caller that must not be taken down by a failure.
     *
     * `uninstall.php` is the caller this exists for. WordPress runs it while it
     * is deleting the plugin, and an exception escaping it aborts the deletion
     * partway - the plugin's files half-removed, the request dead, and the reason
     * only visible if the host happens to have display_errors on. Reporting the
     * failure and returning lets WordPress finish the job it was doing, and the
     * message is what tells the operator that tables survived and need removing
     * by hand.
     *
     * `$reported` is set to whether a failure was reported, and `$reverted` to
     * whatever the sweep got through. Both are by-reference because a return
     * value cannot carry two answers, and the caller needs to distinguish
     * "nothing to reverse" from "reversed some, then failed" - the first means
     * the data really is gone, the second means it is not.
     *
     * @param list<string>|null $reverted  Identifiers reversed, by reference.
     * @param bool|null         $reported  Whether a failure was reported, by reference.
     * @return bool True when the removal completed.
     */
    public function runWithoutThrowing(?array &$reverted = null, ?bool &$reported = null): bool
    {
        $reverted = [];
        $reported = false;

        try {
            $reverted = $this->__invoke();

            return true;
        } catch (Throwable $e) {
            $reported = true;

            // Read off the migrator rather than from the failed call, because the
            // throw unwound the assignment. The migrator records as it goes for
            // exactly this reason - see {@see Migrator::lastRunReverted()}.
            $reverted = $this->migrator->lastRunReverted();

            MigratesOnActivation::reportToErrorLog($e);

            return false;
        }
    }
}
