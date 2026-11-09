<?php
declare(strict_types=1);

namespace App\Database\Seeders;

use Framework\Database\Seeding\Seeder;
use Framework\Database\Seeding\SeederRegistry;
use RuntimeException;
use Throwable;

/**
 * The root seeder: what `db:seed` runs when no `--class=` is given.
 *
 * ## What it is for
 *
 * A project's seeders accumulate, and the ones lower down the list start to
 * depend on the ones above them — a lead needs the user it is assigned to, a
 * report needs the leads. `DatabaseSeeder` is where that order is written down:
 * it runs the others, in the sequence they were written to be run in, so a
 * developer who has just cloned the repository runs one command and gets a
 * database that works.
 *
 * Seeding through this class rather than letting `db:seed` run every seeder
 * alphabetically is what makes the order a decision. {@see SeederRegistry} sorts
 * by name deliberately — it is the only ordering that is reproducible across
 * filesystems — and alphabetical order is not dependency order. `database`
 * happens to sort before `lead`, which is precisely backwards for the one seeder
 * that calls the rest.
 *
 * ## Why it calls through the registry
 *
 * The alternative is injecting each seeder as a constructor argument, which is a
 * second place that knows the project's seeder list: adding `ReportSeeder` would
 * mean adding it to `database/seeders/`, to this constructor, and to `run()` —
 * and forget either of the last two and the seeder exists but never runs. Going
 * through the registry means the file's presence in the directory is the only
 * thing that has to be true.
 *
 * A missing name is therefore a **hard failure**, not a skipped step. It means
 * this class names a seeder that does not exist, which is a broken project rather
 * than a project with one fewer seeder — and a silently skipped dependency is
 * discovered as a foreign-key violation three seeders later, with nothing in the
 * output pointing at the cause.
 *
 * ## Idempotency
 *
 * This class writes nothing of its own, so it has none to declare: each seeder it
 * calls is responsible for its own, as {@see \Framework\Database\Seeding\SeederInterface}
 * requires. Running `db:seed` twice runs this twice, which runs each seeder
 * twice, which is exactly the case those seeders are written to handle.
 */
final class DatabaseSeeder extends Seeder
{
    /**
     * The seeders this one runs, in order.
     *
     * Names rather than classes, because a name is what `--class=` takes and what
     * {@see SeederRegistry::find()} resolves — so the name written here is the
     * name a developer can run on its own to debug it.
     *
     * @var list<string>
     */
    private const SEEDERS = [
        // Nothing above it yet. A seeder for users goes first when assignment by
        // id stops being a fixture detail and starts mattering — the leads below
        // reference user 1, which the fixtures treat as an existing account.
        'lead',
    ];

    public function __construct(private readonly SeederRegistry $registry)
    {
    }

    public function run(): void
    {
        foreach (self::SEEDERS as $name) {
            $seeder = $this->registry->find($name);

            if ($seeder === null) {
                throw new RuntimeException(sprintf(
                    '%s names the seeder "%s", which does not exist. Available: %s',
                    self::class,
                    $name,
                    implode(', ', $this->registry->names()) ?: '(none)'
                ));
            }

            // Caught only to name the seeder that failed, then re-thrown: the
            // exception travels on to `db:seed`, which reports it. Swallowing it
            // here would let the seeders after this one run against a database
            // this one never finished populating.
            try {
                $seeder->run();
            } catch (Throwable $e) {
                throw new RuntimeException(sprintf(
                    'Seeder "%s" failed, so the seeders after it were not run: %s',
                    $name,
                    $e->getMessage()
                ), 0, $e);
            }
        }
    }
}
