<?php
declare(strict_types=1);

namespace Framework\Database\Lifecycle;

use Framework\Database\Exceptions\MigrationException;
use Framework\Database\Migrator;
use Throwable;

/**
 * Brings the schema up to date when the plugin is activated.
 *
 * Why this is not one line in the entry file
 *
 * The activation callback is the worst possible place to put logic. It runs
 * inside WordPress's plugin-activation request, where an uncaught throwable
 * produces a fatal that WordPress reports as "the plugin could not be activated"
 * with nothing else - the operator sees a white screen and no plugin, and the
 * cause is nowhere. It also runs *once*, on a code path no test can reach
 * without a live WordPress install, so logic written there is logic nothing
 * verifies.
 *
 * So the entry file registers a callback that does nothing but delegate here,
 * and every decision lives in a class this suite can drive directly.
 *
 * Treating activation as pending-only, not as a rebuild
 *
 * `runPending()` applies what has not been applied and records it under one new
 * batch. It deliberately does not do the two things that sound reasonable at
 * activation and are not:
 *
 *  - **It does not drop anything.** A `migrate:fresh` would destroy the data of
 *    an install that is being re-activated - which is what happens when a site
 *    is restored from a backup, when a plugin is deactivated and reactivated
 *    during debugging, or when a host toggles plugins during a migration. The
 *    whole point of the ledger is that the second activation finds nothing
 *    pending and does nothing at all.
 *  - **It does not reverse anything.** `down()` is compensating logic, and
 *    activation is not a compensation.
 *
 * Failure is reported, never fatal
 *
 * A migration that throws stops the batch, and the exception is caught here and
 * handed to the configured reporter. Left uncaught it would abort the activation
 * request - and the state it leaves behind is one the framework already knows
 * how to recover from: the tracking table records only the migrations that
 * genuinely completed, every later one is still pending, and `migrate` resumes
 * from where the run stopped. Swallowing the exception is therefore not a
 * degradation; the recovery path is designed for exactly this half-applied
 * state. What the operator must not be left without is the *message*, which is
 * what the reporter is for.
 *
 * Why the reporter is injected
 *
 * Because a framework class cannot know where a given project wants to be told
 * about a failed deploy. The default writes to the error log - present on every
 * real install, and read by whoever is looking at the site afterwards - and a
 * project that has a real channel passes its own callable.
 */
class MigratesOnActivation
{
    /**
     * @param Migrator                    $migrator The engine to run. Injected, so a
     *                                              test drives this against a temporary
     *                                              migration directory and a fake database.
     * @param (callable(Throwable): void)|null $reporter
     *                                              Called when a migration fails, with the
     *                                              exception. Null uses {@see reportToErrorLog()}.
     */
    public function __construct(
        private readonly Migrator $migrator,
        private readonly mixed $reporter = null,
    ) {
    }

    /**
     * Apply every pending migration.
     *
     * @return list<string> Identifiers applied, in execution order. Empty when the
     *                      schema was already up to date - the state a second
     *                      activation is expected to find.
     */
    public function __invoke(): array
    {
        try {
            return $this->migrator->runPending();
        } catch (MigrationException $e) {
            $this->report($e);

            return $this->migrator->lastRunApplied();
        } catch (Throwable $e) {
            // A `Throwable` that is not a `MigrationException` is not a failing
            // migration - the migrator wraps every one of those. It is a failure
            // of the *migrator*, and the likeliest of those is the database
            // handle: activation runs before the plugin's own `plugins_loaded`
            // has fired, and an install where `$wpdb` is unreachable, or where
            // `CREATE TABLE` is refused, surfaces here rather than as a failed
            // migration. Caught anyway, because the alternative is a fatal during
            // activation, which reports nothing at all.
            $this->report($e);

            return [];
        }
    }

    /**
     * Hand a failure to the reporter, or to the error log when none was given.
     */
    private function report(Throwable $e): void
    {
        if ($this->reporter !== null) {
            ($this->reporter)($e);

            return;
        }

        self::reportToErrorLog($e);
    }

    /**
     * The default reporter: one line in the error log naming the failure.
     *
     * Wrapped rather than guarded with `function_exists()` on its own, because
     * the whole point of this method is to be the *last* thing that runs when
     * something has already gone wrong. A reporter that could itself throw would
     * turn a reported failure into an unreported fatal - the exact outcome this
     * class exists to prevent. `error_log()` is also the same fallback
     * {@see \App\Providers\RouteServiceProvider} uses for the same reason.
     *
     * Why the wording is a parameter
     *
     * This method is no longer only activation's. `uninstall.php` reports
     * through it too, and the line it produced said "activation could not
     * complete the database migrations", which is not merely imprecise on an
     * uninstall, it points the reader in the wrong direction entirely. Someone
     * debugging a plugin that would not delete would be told to go and look at
     * activation, where nothing is wrong. A log line that names the wrong
     * operation is worse than one that names none, because it is *acted on*.
     *
     * `$operation` is the phrase that goes between the prefix and the colon, so
     * the two call sites each describe what they were doing. It is not
     * interpolated from user input and has two literal values.
     */
    public static function reportToErrorLog(Throwable $e, string $operation = 'activation could not complete the database migrations'): void
    {
        try {
            error_log(sprintf(
                'WP Mini Laravel: %s: %s: %s',
                $operation,
                $e::class,
                $e->getMessage()
            ));
        } catch (Throwable) {
            // Nothing left to report *through*. The request must still finish: the
            // schema change the migration got through is applied and recorded, and
            // `migrate` will resume from there.
        }
    }
}
