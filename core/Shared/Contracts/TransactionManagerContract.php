<?php
declare(strict_types=1);

namespace Core\Shared\Contracts;

use Throwable;

/**
 * Runs a unit of work so that it either lands entirely or not at all.
 *
 * ## Why the callback form, and not begin/commit/rollBack
 *
 * A manager that exposes three methods asks every caller to write the same
 * correct sequence, and the sequence has a failure mode that is easy to get
 * wrong:
 *
 *     $manager->begin();
 *     try {
 *         $a = $repo->save($note);
 *         $b = $audit->record(...);      // throws here
 *         $manager->commit();
 *     } catch (Throwable $e) {
 *         $manager->rollBack();          // easy to forget
 *         throw $e;                      // easy to swallow
 *     }
 *
 * Every one of those lines is a chance to be wrong, and the consequences are
 * asymmetric: forgetting `rollBack()` leaves a half-written change committed on
 * the next request that happens to reuse the connection. Nothing about the
 * mistake is visible at the call site.
 *
 * {@see transaction()} inverts it. The caller supplies the work, the manager owns
 * the try/catch/finally, and there is no sequence left to get wrong:
 *
 *     $manager->transaction(function () use ($note) {
 *         $saved = $this->repository->save($note);
 *         $this->recordAudit($saved);
 *         return $saved;
 *     });
 *
 * That is the whole reason this interface has one method instead of three. It is
 * not a stylistic preference — it is that the correct version cannot be written
 * incorrectly, and the three-method version can.
 *
 * ## Depth
 *
 * Calling {@see transaction()} inside another {@see transaction()} must not
 * start a second, independent transaction. A database cannot commit an inner
 * transaction without committing the outer one with it, so a naive implementation
 * either errors outright or — worse — lets the inner `commit()` make the outer
 * work durable before the outer work has finished, which is the exact thing the
 * outer transaction was protecting.
 *
 * The expected behaviour is that the outermost call is the real transaction and
 * inner calls join it. What an inner *failure* does is the implementation's
 * decision, and the two reasonable answers are worth naming: it may abort the
 * whole unit of work (a savepoint rollback, or simply letting the exception
 * propagate to the outer frame), or it may be caught by the inner caller and
 * allow the outer work to continue. Both are correct for different situations,
 * which is why this interface does not legislate it — but an implementation must
 * pick one deliberately, and must not silently do neither.
 *
 * ## Contract for implementations
 *
 * - The callback runs exactly once.
 * - Its return value is {@see transaction()}'s return value.
 * - A throwable from the callback propagates to the caller *after* the work is
 *   rolled back. Swallowing it would turn a failed operation into a silent
 *   success.
 */
interface TransactionManagerContract
{
    /**
     * Run the callback inside a transaction and return what it returns.
     *
     * @template TReturn
     *
     * @param callable(): TReturn $callback The unit of work. Receives no
     *                                      arguments: a manager that passed
     *                                      itself in would invite the callback to
     *                                      call `commit()` on it, which is the
     *                                      three-method design coming back in
     *                                      through the window.
     *
     * @return TReturn Whatever the callback returned.
     *
     * @throws Throwable Whatever the callback threw, after rolling back. Declared
     *                   because an implementation that catches and discards it
     *                   would report a failed write as a success — the one
     *                   outcome a transaction manager must never produce.
     */
    public function transaction(callable $callback): mixed;
}
