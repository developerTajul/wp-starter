<?php
declare(strict_types=1);

namespace Framework\Database\Seeding;

/**
 * A named set of rows to write into the database.
 *
 * What a seeder is for, and what it is not
 *
 * A seeder populates a database with data a *developer* needs: a few rows to
 * look at while building a screen, a known set for a manual test, the fixtures a
 * staging environment needs to be useful. It is a development tool, and the
 * thing that distinguishes it from a migration is the rule below.
 *
 * A seeder is re-runnable, and must be written to be
 *
 * Migrations are recorded - {@see \Framework\Database\Migrator} keeps a ledger
 * and never runs one twice. **Seeders are not.** Running `db:seed` twice runs
 * every seeder twice, and there is no ledger to stop it, because "what has been
 * seeded" is not a question with one answer: a seeder may be adding rows, or
 * resetting a fixture, or filling a gap, and only its author knows which.
 *
 * That makes idempotency the **seeder's responsibility**. The two shapes that
 * satisfy it:
 *
 *  - **Look before writing.** A seeder that checks whether its rows exist and
 *    skips them is safe to run any number of times, and is the right shape for
 *    reference data - statuses, default settings - which must be present and
 *    must not be duplicated.
 *  - **Clear then write.** A seeder that deletes what it is about to write is
 *    safe to run any number of times and converges on the same state, which is
 *    the right shape for demo data whose exact contents matter.
 *
 * A seeder that only inserts is neither, and running it twice produces visible
 * duplicates. That is a legitimate choice for a seeder whose whole purpose is to
 * generate volume, and it is then a seeder nobody should run on a database they
 * care about - which is why {@see Seeder} offers no default implementation that
 * would let the decision be made by omission.
 *
 * Why the interface has a name
 *
 * `db:seed --class=` selects a seeder by name, and a registry keyed by class name
 * would make the name the PHP class - `LeadSeeder`. A `getName()` that returns
 * `lead` lets a project name seeders the way it names everything else, and lets
 * the name stay stable if the class is moved. The default in {@see Seeder}
 * derives it from the class name, so most seeders never override it.
 */
interface SeederInterface
{
    /**
     * Write this seeder's data.
     *
     * Called with no arguments, because the seeder is expected to obtain what it
     * needs by dependency injection - the container resolves it, so a seeder that
     * needs a repository declares it in its constructor and receives the same
     * instance the application uses. See {@see \Framework\Database\Seeding\SeederRegistry},
     * which resolves through the container for exactly this reason.
     *
     * A seeder that throws aborts the run: `db:seed` reports which seeder failed
     * and stops rather than continuing into a database that is now half-seeded.
     */
    public function run(): void;

    /**
     * The name `--class=` selects this seeder by.
     *
     * Lower-case and convention-free beyond that - only that two seeders must not
     * share one, because a name that matches two seeders is a command whose
     * result depends on directory read order.
     */
    public function getName(): string;
}
