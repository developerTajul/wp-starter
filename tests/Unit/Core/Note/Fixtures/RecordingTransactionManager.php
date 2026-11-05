<?php
declare(strict_types=1);

namespace Tests\Unit\Core\Note\Fixtures;

use Core\Shared\Contracts\TransactionManagerContract;
use Throwable;

/**
 * A transaction manager that records its depth instead of talking to a database.
 *
 * ## What it has to get right
 *
 * The contract's hard requirement is that a nested {@see transaction()} call
 * *joins* the outer one rather than opening a second. A fake that simply ran the
 * callback would satisfy every test that only cares whether the work happened,
 * and would hide a service that opened two independent transactions on a path
 * that must be atomic — which is the exact bug this double exists to catch.
 *
 * So it tracks depth, and it makes the depth observable: {@see maxDepth} is how a
 * test asserts that `recordAudit()` nested rather than ran after. A service that
 * moved the audit write outside the transaction would still pass a test that only
 * checked the audit ran; it would fail the depth assertion.
 *
 * ## What it fakes about failure
 *
 * Rolling back is simulated by discarding the writes a nested manager made, which
 * a fixture cannot really do — there is no journal here. What *is* faithful is the
 * part the service depends on: a throwable from the callback propagates to the
 * caller, and {@see rollbacks} records that a rollback was attempted. The
 * service's own contract is "re-throw after rollback", and that is what these
 * counters let a test pin down.
 */
final class RecordingTransactionManager implements TransactionManagerContract
{
    /**
     * The number of currently-open transactions.
     */
    public int $depth = 0;

    /**
     * The deepest nesting reached during the test.
     *
     * 1 means the service never nested; 2 means one call joined another.
     */
    public int $maxDepth = 0;

    /**
     * How many transactions were started at depth 0 — the real ones, as opposed
     * to the joins.
     */
    public int $opened = 0;

    public int $committed = 0;

    public int $rollbacks = 0;

    /**
     * How many times the callback ran at all.
     */
    public int $entered = 0;

    /**
     * Set to a throwable to make {@see transaction()} fail on entry, which is how
     * a database that cannot be reached is simulated.
     */
    public ?Throwable $failWith = null;

    /**
     * The depth at which each callback was entered, in order, so a test can assert
     * the shape of the nesting rather than only its maximum.
     *
     * @var list<int>
     */
    public array $entries = [];

    public function transaction(callable $callback): mixed
    {
        if ($this->failWith !== null) {
            $failure = $this->failWith;
            $this->failWith = null;

            throw $failure;
        }

        // Depth 0 is the outermost call, and that is the only one that would issue
        // a real BEGIN. Everything above it joins.
        $isOutermost = $this->depth === 0;

        if ($isOutermost) {
            $this->opened++;
        }

        $this->depth++;
        $this->maxDepth = max($this->maxDepth, $this->depth);
        $this->entries[] = $this->depth;
        $this->entered++;

        try {
            $result = $callback();
        } catch (Throwable $e) {
            $this->depth--;

            // A rollback is only meaningful for the transaction that owns the
            // changes. An inner frame that threw has its failure handled by the
            // outer frame, so only the outermost call rolls back — which is what
            // a savepoint-less implementation does, and what the contract's
            // "abort the whole unit of work" option describes.
            if ($isOutermost) {
                $this->rollbacks++;
            }

            throw $e;
        }

        $this->depth--;

        if ($isOutermost) {
            $this->committed++;
        }

        return $result;
    }
}
