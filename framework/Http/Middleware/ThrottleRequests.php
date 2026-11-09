<?php
declare(strict_types=1);

namespace Framework\Http\Middleware;

use Closure;
use Core\Shared\Domain\Contracts\RateLimiterContract;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Exceptions\TooManyRequestsHttpException;
use Framework\Http\Request;

/**
 * Refuses a request that has exceeded its rate limit.
 *
 *     ->middleware('throttle:60,1')       // 60 requests per minute
 *     ->middleware('throttle:5,10')       // 5 requests per 10 minutes
 *
 * Why aliased as a parameter rather than subclassed
 *
 * The two forms of every other guard in this framework are "subclass it when the
 * value is part of the class's meaning" and "parameterize it when the value is a
 * property of the route". A limit is unambiguously the second: two routes with
 * different limits are two policies, not two kinds of guard, and a reader audits
 * `'throttle:60,1'` next to the route far more easily than a class defined
 * elsewhere. So this takes its parameters from the route and is used through the
 * `throttle` alias.
 *
 * Where the key comes from, and why it is not the route
 *
 * The key is `the authenticated user if there is one, otherwise the client's IP`.
 * That is the standard choice and the reasoning is worth stating, because a
 * caller can override it and should know what they are overriding:
 *
 *   - **By user id when logged in.** A logged-in user behind a shared office NAT
 *     should not consume their colleagues' allowance, and a limit that ignored
 *     identity would punish an entire building for one person's traffic.
 *   - **By IP otherwise.** An anonymous client has no identity to key on, and an
 *     IP is the finest-grained thing available. It is spoofable behind a proxy
 *     that has not been configured to set a real client address, which is a
 *     limitation of the *transport*, not of this middleware - see
 *     {@see clientKey()} for what is deliberately not trusted.
 *
 * The key is namespaced with a per-route prefix - the route's display path - so
 * that two routes with the same limit do not share a counter. Without it, a
 * request to `leads/create` would consume the allowance for `leads/store`, and a
 * user who opened the form and submitted it once would have spent two of their
 * allowance on one action.
 *
 * Fails closed when the limiter cannot run
 *
 * If the limiter throws - the table is missing, the database is unreachable - the
 * request is refused with a 503 rather than allowed through. This is the same
 * choice {@see CheckCapability} and {@see VerifyAdminNonce} make, for the same
 * reason: a guard that cannot run has not guarded anything, and a rate limiter
 * that silently stops limiting is invisible precisely when traffic is high enough
 * to need it.
 */
class ThrottleRequests implements Middleware
{
    public function __construct(private readonly RateLimiterContract $limiter)
    {
    }

    /**
     * Check the limit for this request.
     *
     * @param list<string> $arguments `maxAttempts,decayMinutes` from the route.
     */
    public function handle(Request $request, Closure $next, string ...$arguments): ResponseInterface
    {
        // No parameters is a misconfiguration, not "no limit". A route that says
        // `'throttle'` plainly meant to say a number, and defaulting to some
        // invented limit would make the typo invisible - the same reasoning
        // CheckCapability applies to `'capability:'`.
        if ($arguments === []) {
            throw new \InvalidArgumentException(
                'The throttle middleware needs a limit. Write it as [throttle:60,1], the maximum '
                . 'number of attempts, then the window in minutes.'
            );
        }

        $maxAttempts = (int) ($arguments[0] ?? 0);
        $decayMinutes = (int) ($arguments[1] ?? 1);

        // A window of zero minutes would reset instantly - a limiter that never
        // limits. Refused rather than defaulted, because it is a typo in a routes
        // file and defaulting would hide it.
        $decaySeconds = max(1, $decayMinutes) * 60;

        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException(sprintf(
                'The throttle middleware was given [%s] as a limit, which is not a usable number of '
                . 'attempts. Write it as [throttle:60,1].',
                $arguments[0] ?? ''
            ));
        }

        $result = $this->limiter->hit(
            $this->keyFor($request),
            $maxAttempts,
            $decaySeconds
        );

        if ($result->isDenied()) {
            // A TooManyRequestsHttpException rather than a JSON body built here,
            // so the `Retry-After` header is guaranteed to be present and the
            // handler renders the same envelope every other failure uses. The
            // exception *is* the response decision.
            throw new TooManyRequestsHttpException(
                $result->secondsUntilReset(),
                'Too many attempts. Please wait before trying again.'
            );
        }

        return $next($request);
    }

    /**
     * The route's display path, so two routes never share a counter.
     *
     * Derived from the request itself rather than set from outside, and that is a
     * correction worth recording: the first version exposed a `scopeTo()` setter
     * for the router to call, which could not work. The pipeline resolves each
     * middleware from the container *inside* `carry()`, so no caller holds the
     * instance to configure - the setter would have been dead code that read as a
     * working feature, and every route would have silently shared one bucket.
     *
     * Deriving it from the request means there is nothing to wire and nothing to
     * forget.
     */
    private function scope(Request $request): string
    {
        $path = $request->uri();

        // The query string is stripped: `/leads?page=2` and `/leads?page=3` are
        // one route, and treating them as two would give a paginating client a
        // fresh allowance per page.
        return parse_url($path, PHP_URL_PATH) ?: $path;
    }

    /**
     * The counter key for this request.
     *
     * Prefixed with the route's scope so two routes do not share an allowance ,
     * see the class docblock.
     */
    private function keyFor(Request $request): string
    {
        return $this->scope($request) . '|' . $this->identity($request);
    }

    /**
     * Who is being limited.
     *
     * The user id when there is one, the client address otherwise. Kept separate
     * from {@see keyFor()} so a subclass can change the identity without having
     * to reconstruct the namespacing.
     */
    protected function identity(Request $request): string
    {
        $userId = $this->userId();

        if ($userId > 0) {
            return 'user:' . $userId;
        }

        return 'ip:' . $this->clientKey($request);
    }

    /**
     * The current user's id, or 0.
     *
     * `function_exists` because this middleware is exercised by unit tests that
     * load no WordPress.
     */
    private function userId(): int
    {
        if (!function_exists('get_current_user_id')) {
            return 0;
        }

        return (int) get_current_user_id();
    }

    /**
     * The client's address, as far as it can be established.
     *
     * What is deliberately not trusted
     *
     * `X-Forwarded-For` and `X-Real-IP` are **not** read. They are client-supplied
     * headers, and honouring them by default would let any caller set an arbitrary
     * value on every request - which does not merely weaken the limit, it removes
     * it, because a different address per request is a different counter per
     * request. A proxy that genuinely terminates TLS and sets these headers is a
     * deployment fact this framework cannot detect, and a middleware that guessed
     * would be wrong on every install that is *not* behind one.
     *
     * A project that is behind a trusted proxy should subclass this and override
     * this method to read the header its proxy sets, having first verified that
     * the proxy strips the header from inbound client requests. Overriding it is a
     * deliberate act with a documented precondition, which is the right shape for
     * a decision with this failure mode.
     *
     * `''` when WordPress is not loaded and the superglobal is absent: the
     * fallback is a single shared bucket rather than no limit at all. Failing
     * closed matters more here than distinguishing anonymous callers, and in a
     * real request `REMOTE_ADDR` is always set.
     */
    protected function clientKey(Request $request): string
    {
        $address = $request->server('REMOTE_ADDR');

        return is_string($address) && $address !== '' ? $address : 'unknown';
    }
}
