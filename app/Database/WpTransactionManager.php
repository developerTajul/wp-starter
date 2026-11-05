<?php
declare(strict_types=1);

namespace App\Database;

use Core\Shared\Contracts\TransactionManagerContract;
use RuntimeException;
use Throwable;
use wpdb;

/**
 * Units of work on `$wpdb`, with nesting that joins.
 *
 * ## What the depth rule costs, and why it is not negotiable
 *
 * {@see TransactionManagerContract} requires that an inner {@see transaction()}
 * joins the outer one rather than starting a second. MySQL has no nested
 * transactions — a real `START TRANSACTION` inside one implicitly commits the
 * outer — so the naive implementation does not merely fail, it *silently
 * promotes* the inner unit's boundary to the outer one's. The audit write inside
 * `NoteService::create()` is exactly the call this would break: it would commit
 * the note before the outer frame decided whether to.
 *
 * So depth is counted, and only the outermost call issues SQL. An inner call
 * runs the callback directly, in the frame it was handed.
 *
 * ## Why savepoints are not used
 *
 * They are the usual answer and they were considered. A savepoint per nested
 * level would let an inner frame roll back *its own* work while the outer
 * continues — the contract's second sanctioned option — and the cost is that
 * every nested level issues two more statements, on a path that is not hot
 * enough to matter for throughput but is hot enough that a failed savepoint
 * becomes a third failure mode to reason about (older MySQL, storage engines
 * without savepoint support, a `ROLLBACK TO` that names a savepoint the server
 * has already discarded).
 *
 * The contract permits either behaviour as long as an implementation picks one
 * deliberately. This one picks the first — an inner failure propagates and the
 * whole unit of work is rolled back — because that is what every caller in this
 * codebase actually wants: an audit write that failed means the change it
 * describes must not land either. Choosing it also means the failure semantics
 * are the *simple* ones, and simplicity is worth more than the granularity
 * nobody has asked for.
 *
 * ## The rollback flag
 *
 * `$wpdb` tracks whether any statement has failed inside a transaction, and
 * `commit` on a tainted transaction is not a commit. Catching the throwable,
 * rolling back, and re-throwing is therefore the whole body — and the re-throw
 * is the part that must not be forgotten, since swallowing it would report a
 * failed unit of work as a success.
 */
final class WpTransactionManager implements TransactionManagerContract
{
    /**
     * How many frames are currently open.
     *
     * Instance state, and deliberately not static: two managers are two
     * independent units of work, and a static counter would make one manager's
     * nesting silently extend another's.
     */
    private int $depth = 0;

    /**
     * @param wpdb|null $wpdb WordPress database handle. Defaults to the `$wpdb`
     *                         global, resolved lazily for the same reason the
     *                         repositories do it — a provider's `register()` runs
     *                         before WordPress has necessarily finished setting
     *                         `$wpdb` up.
     */
    public function __construct(private ?wpdb $wpdb = null)
    {
    }

    /**
     * The nesting depth, for a test that wants to assert the join happened.
     *
     * Published rather than private so a test does not have to infer the shape of
     * the nesting from the SQL the manager issued — which is the property it
     * actually cares about.
     */
    public function depth(): int
    {
        return $this->depth;
    }

    public function transaction(callable $callback): mixed
    {
        $db = $this->db();

        // An inner call joins. The callback runs in the caller's frame and the
        // boundary is not touched — see the class docblock for why a second
        // `START TRANSACTION` here would be worse than an error.
        if ($this->depth > 0) {
            return $callback();
        }

        $db->query('START TRANSACTION');
        $this->depth++;

        try {
            $result = $callback();
        } catch (Throwable $e) {
            // Depth is decremented before the rollback so a `ROLLBACK` that
            // itself fails — which throws out of here — does not leave the
            // manager believing a transaction is still open.
            $this->depth--;

            $db->query('ROLLBACK');

            // Re-thrown, never swallowed. A transaction manager that reported a
            // failed unit of work as a success is the one outcome the contract
            // names as unacceptable.
            throw $e;
        }

        $this->depth--;

        $db->query('COMMIT');

        return $result;
    }

    /**
     * The WordPress database handle, resolved on first use.
     */
    private function db(): wpdb
    {
        if ($this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }

        if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof wpdb) {
            throw new RuntimeException(
                'No $wpdb instance is available. WpTransactionManager needs a WordPress database '
                . 'handle; pass one to the constructor when running outside a loaded WordPress.'
            );
        }

        return $this->wpdb = $GLOBALS['wpdb'];
    }
}
