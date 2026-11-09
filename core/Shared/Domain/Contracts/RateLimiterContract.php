<?php
declare(strict_types=1);

namespace Core\Shared\Domain\Contracts;

use Core\Shared\RateLimitResult;

/**
 * Counts attempts against a key and says whether the next one is allowed.
 *
 * ## Why this is a port and not a class
 *
 * The property that matters about a rate limiter is **atomicity**, and atomicity
 * is a property of the storage, not of the logic. A limiter that reads a counter,
 * decides, and writes it back is wrong under concurrency no matter how carefully
 * the middle step is written: two requests that both read `9` against a limit of
 * `10` both decide "allowed" and both write `10`, so eleven requests pass a
 * ten-request limit. The window where that happens is exactly the window an
 * attacker is trying to hit.
 *
 * Making the decision and the increment a single operation is a storage feature —
 * a conditional `UPDATE`, an atomic `INSERT … ON DUPLICATE KEY UPDATE`, a Redis
 * `INCR` with a TTL. It cannot be expressed in an implementation-agnostic way, so
 * the interface states the *guarantee* and each adapter delivers it in whatever
 * its storage provides.
 *
 * ## The guarantee every implementation must meet
 *
 * {@see hit()} must be atomic with respect to concurrent callers sharing a key.
 * Two simultaneous calls against a limit of `1` must produce exactly one allowed
 * result. An implementation that cannot guarantee that must say so rather than
 * approximate it, because a limiter that is *usually* right is a limiter that
 * reports no problem while being bypassed.
 *
 * ## Why one method rather than `attempt()` + `hit()` + `clear()`
 *
 * Laravel's limiter exposes several, and the split is what invites the bug: a
 * caller that checks `tooManyAttempts()` and then calls `hit()` separately has
 * written a read-then-write, which is the exact race this interface exists to
 * rule out. One method that counts *and* decides cannot be used that way.
 *
 * `clear()` is offered separately because resetting on success — the standard
 * "forgive a user who proved themselves" behaviour — is a deliberate second
 * decision rather than part of the check, and forcing it into `hit()` would make
 * a limiter that always forgives the only one available.
 */
interface RateLimiterContract
{
    /**
     * Count one attempt against a key and report whether it is allowed.
     *
     * The count is incremented **whether or not** the attempt is allowed — an
     * over-limit request that did not increment would let a client hammer at
     * exactly the limit forever, and would make `Retry-After` uncomputable
     * because the window would never advance under load.
     *
     * @param string $key         What is being limited. Callers compose this —
     *                            `'login:' . $ip`, `'lead:' . $userId` — because
     *                            the framework has no opinion about the scope of
     *                            a limit, and a key the limiter invented would be
     *                            a policy the caller could not change.
     * @param int    $maxAttempts How many are allowed inside the window.
     * @param int    $decaySeconds How long the window lasts, counted from the
     *                            *first* attempt in it. A fixed window rather
     *                            than a sliding one: a sliding window needs the
     *                            timestamp of every attempt, and the memory that
     *                            costs grows with the traffic being limited,
     *                            which is the wrong shape for a defence against
     *                            high traffic.
     *
     * @return RateLimitResult Whether the attempt is allowed, and when to retry.
     */
    public function hit(string $key, int $maxAttempts, int $decaySeconds): RateLimitResult;

    /**
     * Forget a key's history.
     *
     * Called after a success — a correct password, a completed form — so that a
     * user who mistyped twice and then got it right starts clean. Without it, the
     * count survives a legitimate success and a user who is merely clumsy is
     * eventually locked out.
     */
    public function clear(string $key): void;
}
