<?php

declare(strict_types=1);

namespace Core\Shared\Domain\Contracts;

/**
 * PORT: transaction boundary around a use case.
 *
 * ## Why both `transaction()` and `run()` are on the interface
 *
 * Neither name could be dropped. Two application layers each picked one: the
 * legacy services called `transactions->transaction()` and the lead services
 * call `transactionManager->run()`. A contract with only one of the two makes
 * the other module's every use case fail at the first call — which is exactly
 * what a `run()`-only contract did to the first of them, and what a
 * `transaction()`-only one would do to the five lead services.
 *
 * Both spellings are the same operation and an implementation must treat them
 * identically, including the nesting guarantee below — they are one method with
 * two names, not two entry points that might disagree about depth.
 *
 * `transaction()` reads better and is what new code should call: the callback
 * form *is* the transaction, and a name that could describe any callback makes
 * the call site say less than it means. `run()` stays because removing it would
 * mean editing frozen code, and the contract is the cheaper place to absorb a
 * second name than the domain is.
 *
 * ## The nesting guarantee
 *
 * A `transaction()` call made *inside* another must join the outer one rather
 * than open a second. This is the property the whole port exists for: a nested
 * write inside `createLead()` runs in the create's frame, and two independent
 * transactions there would commit the outer row before the frame that owns it
 * decided whether to — leaving a half-written record, which is exactly the
 * state the boundary rules out.
 *
 * Implementations therefore track depth, and only the outermost frame issues a
 * real BEGIN, COMMIT, or ROLLBACK. A failed inner frame propagates to the outer
 * one, which is what rolls back; an implementation that rolls back at depth 2
 * would abandon an outer unit of work it does not own.
 */
interface TransactionManagerContract
{
    /**
     * Run a callback inside a transaction and return its result.
     *
     * Rolls back and rethrows when the callback throws. On a nested call, joins
     * the open transaction instead of opening a second.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public function transaction(callable $callback): mixed;

    /**
     * {@see transaction()} under the name the lead services use.
     *
     * The same operation with the same nesting guarantee. An implementation must
     * not route this through a second depth counter — two counters would let
     * `run()` inside `transaction()` look outermost to its own and issue a real
     * COMMIT inside the outer frame.
     *
     * @template T
     *
     * @param callable():T $callback
     *
     * @return T
     */
    public function run(callable $callback): mixed;

    /**
     * Open a unit of work explicitly.
     *
     * For the caller that cannot express its work as one callback — a paginated
     * import, a migration. Prefer {@see transaction()}: it cannot be left open
     * by an early return or a throw the way a manual begin/commit pair can.
     */
    public function beginTransaction(): void;

    /**
     * Commit the open unit of work.
     *
     * Only meaningful as the pair of {@see beginTransaction()}; the callback form
     * commits for you. Committing at depth above zero is a no-op — the outer
     * frame owns the COMMIT.
     */
    public function commit(): void;

    /**
     * Whether a unit of work is currently open.
     *
     * What the error handler asks before attempting a rollback, since rolling
     * back with nothing open is an error that would replace a handled failure
     * with an unhandled one.
     */
    public function inTransaction(): bool;

    /**
     * Abandon the open unit of work.
     *
     * Discards everything written since the outermost frame opened. Called from
     * `transaction()`'s failure path and by the error handler.
     */
    public function rollback(): void;
}
