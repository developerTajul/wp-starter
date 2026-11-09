<?php
declare(strict_types=1);

namespace App\Database\Seeders;

use Core\Lead\Application\Contracts\LeadQueryContract;
use Core\Lead\Domain\Contracts\LeadRepositoryContract;
use Core\Lead\Domain\Entities\LeadEntity;
use Core\Lead\Domain\Enums\LeadStatus;
use Core\Lead\Domain\Exceptions\InvalidLeadException;
use Core\Lead\Domain\ValueObjects\LeadFilter;
use Framework\Database\Seeding\Seeder;
use RuntimeException;
use Throwable;

/**
 * A handful of leads to look at while building a screen.
 *
 * ## Why it goes through the repository
 *
 * Every row here is written by {@see LeadRepositoryContract::create()}, which
 * means it passes the same validation and the same mapping as a lead submitted
 * through a form. That is the point rather than a stylistic preference: seeded
 * data that takes a different path into the table is data the application has
 * never been asked to produce, so a screen built against it can be built against
 * something the real code would refuse to store. When the mapper has a bug — a
 * status the entity will not accept, a timestamp parsed in the wrong zone — this
 * seeder fails, and it fails while the developer is looking at it.
 *
 * ## Why the entity is built directly rather than through `create()`
 *
 * {@see LeadEntity::create()} is the *form* path: it returns a `Result` because a
 * validation failure there is an ordinary outcome a controller turns into a 422.
 * A seeder has no user and no request, and every value below is a constant in
 * this file — so a failure is a bug in this file, not data to be reported. The
 * constructor is called directly and its `InvalidLeadException` is allowed to
 * propagate: a stack trace that names the offending fixture is a better
 * diagnostic than a `Result` nobody is going to branch on, and unwrapping a
 * `Result` that can never be a failure is ceremony.
 *
 * ## Why the repository contract, and not the caching decorator's port
 *
 * The port is what the container binds, so this seeder writes through whatever
 * repository the application is configured with. That is deliberate: a seeder
 * that reached around the configured wiring to a concrete class would be
 * exercising a path no request takes, and a cache invalidation bug would be
 * invisible to the one command whose whole job is to write rows.
 *
 * ## Idempotency: clear, then write
 *
 * Running `db:seed` twice must not produce ten leads. The two shapes that achieve
 * that are described at {@see \Framework\Database\Seeding\SeederInterface}; this
 * seeder takes the second one, because demo data whose exact contents matter is
 * data you want to be able to *reset*.
 *
 * So the seed set is deleted first, keyed by email address. Deleting by address
 * rather than truncating the table is deliberate — this runs against whatever
 * database the developer pointed it at, and `TRUNCATE` would take rows they were
 * working with, including any they added by hand. It removes exactly what it is
 * about to write, and nothing else.
 *
 * ## Why the addresses are at `example.test`
 *
 * `.test` is reserved by RFC 2606 and can never be registered, so a seeder that
 * escapes onto a real install — a developer who forgot which site their terminal
 * was pointed at — cannot put a working address into a table that a real
 * notification might later read. A `@gmail.com` fixture is one misdirected email
 * away from an incident.
 */
final class LeadSeeder extends Seeder
{
    /**
     * The `source` every row this seeder writes carries.
     *
     * A value no real submission produces, which is what makes it usable as the
     * filter in {@see removePhoneOnlyRow()}: rows written by a form or a webhook
     * carry their own source, so a delete restricted to this one cannot reach
     * them, however the fixture set is later edited.
     */
    private const SOURCE = 'seeder';

    /**
     * The leads this seeder owns, keyed by email address.
     *
     * Order is the order they are written in, and it is meaningful to a human
     * reading the admin list: the newest row is the most recently created, so
     * these are written oldest-first to make the listing read top-down as
     * "most recent first" in the way a real table would.
     *
     * Each entry is `[name, email, phone, company, status, assigned_to]`. Written
     * as a list of positional rows rather than as named fields because the value
     * being pinned is the *combination* — the statuses below are what exercise
     * filters, and a reader comparing them wants them side by side.
     *
     * @var list<array{0: string, 1: string|null, 2: string|null, 3: string|null, 4: LeadStatus, 5: int|null}>
     */
    private const LEADS = [
        ['Ada Lovelace', 'ada@example.test', '+44 20 7946 0001', 'Analytical Engines Ltd', LeadStatus::NEW, null],
        ['Grace Hopper', 'grace@example.test', null, 'US Navy', LeadStatus::CONTACTED, 1],
        ['Alan Turing', 'alan@example.test', '+44 20 7946 0003', null, LeadStatus::QUALIFIED, 1],
        ['Katherine Johnson', 'katherine@example.test', '+1 202 555 0147', 'NASA', LeadStatus::CONVERTED, null],
        // No email: exercises the phone-only path through the entity's contact
        // rule, which is the one case a fixture using only addresses never
        // reaches.
        ['Margaret Hamilton', null, '+1 617 555 0198', 'MIT Instrumentation Lab', LeadStatus::LOST, null],
    ];

    /**
     * Both ports are injected, because clearing the previous run needs a read and
     * writing the new one needs a write.
     *
     * The *contracts* rather than the concrete repositories, so the seeder goes
     * through whatever the application is configured with — and so a break in
     * either port's wiring shows up here, at a command a developer runs while
     * watching, rather than at the first request that needs a lead.
     *
     * Two ports rather than one wider one because that is the split the module
     * already has: the containers that type-hint `LeadQueryContract` cannot reach
     * `create()`, and that guarantee is worth keeping in a seeder too.
     */
    public function __construct(
        private readonly LeadRepositoryContract $leads,
        private readonly LeadQueryContract $leadQuery,
    ) {
    }

    public function run(): void
    {
        $this->removeExistingRows();

        $written = 0;

        foreach (self::LEADS as [$name, $email, $phone, $company, $status, $assignedTo]) {
            $stored = $this->leads->create(
                $this->build($name, $email, $phone, $company, $status, $assignedTo)
            );

            // A stored lead always carries its id: the repository contract says
            // an implementation that cannot learn the assigned id must fail
            // loudly, so reaching here with a null id means that guarantee was
            // broken. Refused rather than skipped, because the alternative is
            // five identical leads on the next run.
            if ($stored->id === null) {
                throw new RuntimeException(sprintf(
                    'Saving "%s" returned a lead with no id, so the row cannot be identified. '
                    . 'The storage layer failed to report the assigned id.',
                    $name
                ));
            }

            $written++;
        }

        $this->report($written);
    }

    /**
     * Build the entity from one fixture row.
     *
     * The constructor is called rather than {@see LeadEntity::create()}, for the
     * reason given in the class docblock: these values are constants, so the only
     * way this can fail is if the fixture itself is wrong, and an exception names
     * that case better than a `Result` that would have to be unwrapped into one
     * anyway.
     *
     * The status is passed as the enum case directly. The old shape carried it as
     * a string and needed a second step to apply it, which is why a fixture could
     * name a status the domain had never heard of and only find out when the
     * value was used.
     *
     * @throws InvalidLeadException When the fixture breaks one of the entity's
     *                              invariants — a bug in this file, and the
     *                              message names the entry.
     */
    private function build(
        string $name,
        ?string $email,
        ?string $phone,
        ?string $company,
        LeadStatus $status,
        ?int $assignedTo,
    ): LeadEntity {
        // `InvalidLeadException` names the field that broke, not the fixture row,
        // so it is caught and re-thrown with the row named. A seeder that reported
        // "the name is too long" against a file of five rows would leave the
        // reader to find which one.
        try {
            $lead = new LeadEntity(
                id: null,
                name: $name,
                email: $email,
                phone: $phone,
                companyName: $company,
                status: $status,
                source: self::SOURCE,
            );

            return $assignedTo === null ? $lead : $lead->assignTo($assignedTo);
        } catch (InvalidLeadException $exception) {
            throw new InvalidLeadException(
                sprintf('The seeder fixture for "%s" is not a valid lead: %s', $name, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    /**
     * Delete this seeder's rows, so the run converges on the same state.
     *
     * Looks each one up by address and deletes what it finds, rather than issuing
     * one `DELETE ... IN (...)`: the read port's `findEntityByEmail()` is the
     * same lookup a form submission's duplicate check takes, so a break in it
     * shows up here rather than in production.
     *
     * The phone-only fixture has no address to look up and is therefore not
     * removed — see {@see removePhoneOnlyRow()}, which handles it by source.
     */
    private function removeExistingRows(): void
    {
        $this->removePhoneOnlyRow();

        foreach (self::LEADS as [, $email]) {
            if ($email === null) {
                continue;
            }

            $existing = $this->leadQuery->findEntityByEmail($email);

            if ($existing?->id !== null) {
                $this->leads->delete($existing->id);
            }
        }
    }

    /**
     * Remove the fixture rows that have no email address.
     *
     * An address is what the other removals key on, and the phone-only fixture
     * deliberately has none — without this it would be inserted again on every
     * run, which is exactly the duplicate this seeder exists to avoid.
     *
     * Filtered by `source`, which every row here shares and no row created
     * elsewhere does. That is what makes it safe to delete by a filter rather
     * than by an identifier: it can only ever match rows this seeder wrote.
     */
    private function removePhoneOnlyRow(): void
    {
        $page = $this->leadQuery->getFilteredLeads(new LeadFilter(source: self::SOURCE, perPage: 100));

        foreach ($page->items() as $projection) {
            if ($projection->email !== null) {
                continue;
            }

            $this->leads->delete($projection->id);
        }
    }

    private function report(int $written): void
    {
        // Through WP-CLI when it is loaded — a seeder is run by `db:seed`, and a
        // line on stdout is what the operator expects — and silent otherwise,
        // which is the test case. `Command` guards \WP_CLI the same way.
        if (class_exists('WP_CLI')) {
            \WP_CLI::log(sprintf('  → %d demo lead(s) written.', $written));
        }
    }
}
