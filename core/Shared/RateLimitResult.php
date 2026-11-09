<?php

declare(strict_types=1);

namespace Core\Shared;

/**
 * The outcome of one rate-limit check.
 *
 * ## Why the verdict is recorded rather than derived
 *
 * The obvious design stores the count and the limit, and answers `isAllowed()`
 * by comparing them. It is wrong here, and the reason is in how the limiter is
 * used: {@see \Core\Shared\Domain\Contracts\RateLimiterContract::hit()} counts
 * the attempt *whether or not* it is allowed, so a client sitting at the limit
 * produces results whose count keeps climbing. Any derivation therefore has to
 * reproduce the exact boundary the storage applied — `<=` versus `<` — and the
 * one that gets it wrong reports an allowed request as denied, or worse, tells
 * a blocked client it has attempts left.
 *
 * The storage already made the decision atomically; this object carries it.
 *
 * ## Why `remaining()` floors at zero
 *
 * An over-limit client has a count above the limit, and `limit - count` would go
 * negative. A client rendering "you have -3 attempts left" is a worse answer than
 * "you have none", and a caller that arithmetic'd on the negative would compute a
 * backoff in the wrong direction. Zero is the honest answer to "how many more may
 * you make", which is what the method asks.
 *
 * ## Why this is not an enum or a bool
 *
 * A denied result and an allowed one carry different useful data — a denied
 * caller needs the window it must wait out, an allowed one may want the headroom
 * it has left — and a caller almost always wants the numbers alongside the
 * verdict. Returning a bool and forcing a second call for the numbers is how the
 * count and the decision drift apart between the two reads.
 */
final readonly class RateLimitResult
{
    /**
     * @param bool $allowed           Whether this attempt passed.
     * @param int  $attempts          The count *after* this attempt was included,
     *                                which is what the storage decided on.
     * @param int  $maxAttempts       The limit that was applied.
     * @param int  $secondsUntilReset How long until the window ends and the count
     *                                is forgotten. Meaningful on a denial — it is
     *                                what `Retry-After` carries — and reported on
     *                                an allowance too, since a client may want to
     *                                know when its headroom returns.
     */
    public function __construct(
        public bool $allowed,
        public int $attempts,
        public int $maxAttempts,
        public int $secondsUntilReset,
    ) {}

    /**
     * An attempt that passed.
     *
     * @param int $maxAttempts       The limit that was applied.
     * @param int $secondsUntilReset Seconds left in the window.
     */
    public static function allowed(int $attempts, int $maxAttempts, int $secondsUntilReset): self
    {
        return new self(true, $attempts, $maxAttempts, $secondsUntilReset);
    }

    /**
     * An attempt that was refused.
     *
     * Named `denied()` rather than `blocked()` or `tooMany()` because the
     * question every caller asks is its opposite — `isAllowed()` — and the two
     * should read as the two halves of one decision rather than as two unrelated
     * states.
     */
    public static function denied(int $attempts, int $maxAttempts, int $secondsUntilReset): self
    {
        return new self(false, $attempts, $maxAttempts, $secondsUntilReset);
    }

    /**
     * Whether the request may proceed.
     */
    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    /**
     * Whether the request must be refused.
     *
     * A method rather than `! $result->isAllowed()` at each call site so that the
     * middleware reads as what it means. It is also the one place a future third
     * state would have to be considered, which a negation would silently miss.
     */
    public function isDenied(): bool
    {
        return ! $this->allowed;
    }

    /**
     * The count after this attempt, as the storage reported it.
     */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * The limit that was applied to this attempt.
     */
    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * How many further attempts are permitted in this window.
     *
     * Floored at zero: see the class docblock for why a negative here is worse
     * than a slightly lossy zero.
     */
    public function remaining(): int
    {
        return max(0, $this->maxAttempts - $this->attempts);
    }

    /**
     * Seconds until the window resets and the count is forgotten.
     *
     * What `Retry-After` is built from. Floored at zero for the same reason
     * {@see remaining()} is: a clock skew or a window that expired between the
     * increment and this read would otherwise produce a negative header value,
     * which is not a valid `Retry-After`.
     */
    public function secondsUntilReset(): int
    {
        return max(0, $this->secondsUntilReset);
    }
}
