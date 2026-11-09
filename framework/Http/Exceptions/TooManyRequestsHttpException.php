<?php
declare(strict_types=1);

namespace Framework\Http\Exceptions;

/**
 * The caller has exceeded a rate limit.
 *
 * `Retry-After` is not optional here, and that is why this class exists
 *
 * A 429 without a `Retry-After` header tells a client it was refused and nothing
 * about when to come back - so the only strategies available to it are "hammer
 * again immediately" or "give up", and the first makes the overload it was
 * meant to prevent worse. Since the header is part of a *correct* 429 rather
 * than an embellishment, this constructor requires it. A caller raising this
 * cannot forget it, because there is no way to build one without a value.
 *
 * Seconds, not an HTTP date
 *
 * RFC 9110 permits either a delta-seconds value or an HTTP-date. Seconds is
 * emitted because that is what the limiter actually knows - it computed a decay
 * window, not a wall-clock moment - and converting to a date would introduce a
 * clock dependency and a timezone question for no benefit. A client that wants
 * the absolute time can add it to its own clock, which is the one that matters
 * for its retry.
 *
 * @see \Framework\Http\Middleware\ThrottleRequestsMiddleware The producer.
 */
final class TooManyRequestsHttpException extends HttpException
{
    /**
     * @param int                   $retryAfter Seconds the client should wait.
     * @param string                $message
     * @param array<string, string> $headers    Additional headers beyond `Retry-After`.
     */
    public function __construct(
        int $retryAfter,
        string $message = 'Too many attempts. Please wait before trying again.',
        array $headers = [],
        ?\Throwable $previous = null
    ) {
        // Clamped rather than trusted. A zero or negative value would emit
        // `Retry-After: 0`, inviting exactly the immediate retry this response
        // exists to prevent, and a limiter that computed a decay window can
        // produce one at the boundary. One second is the smallest instruction
        // that means "wait".
        $retryAfter = max(1, $retryAfter);

        parent::__construct(
            $message,
            429,
            ['Retry-After' => (string) $retryAfter] + $headers,
            0,
            $previous
        );
    }

    /**
     * The `Retry-After` value, in seconds.
     *
     * Exposed so a caller can read the decision back without parsing the header
     * it also carries - a test asserting the limiter's window should not have to
     * go through string parsing to do it.
     */
    public function retryAfter(): int
    {
        return (int) $this->headers()['Retry-After'];
    }
}
