<?php
declare(strict_types=1);

namespace Framework\Http\Contracts;

use Closure;
use Framework\Http\Request;

/**
 * A cross-cutting check on the *application* request lifecycle.
 *
 * This is the HTTP-layer counterpart to {@see \Framework\Routing\Contracts\Middleware}.
 * The two interfaces look similar and are deliberately not merged, because they
 * answer different questions:
 *
 *  - The routing middleware runs inside WordPress's `permission_callback`, which
 *    is a yes/no seam: it may only return a WP_Error (deny) or nothing (allow).
 *    WordPress owns the response there.
 *  - This middleware runs inside the pipeline, which the application owns. It can
 *    inspect the request, rewrite it, short-circuit with any ResponseInterface,
 *    and receive the response on the way back out to add a header or a cookie.
 *
 * A class may implement both if it genuinely needs to act in both places, but
 * reaching for this one on a REST route registered through the Router does
 * nothing — nothing calls it. See {@see \Framework\Http\Middleware\Pipeline}.
 *
 * ## $next is required, and a terminating middleware still declares it
 *
 * PHP rejects an implementation that drops a parameter the interface requires:
 * declaring `handle(Request $request)` here is a **fatal error at class-load**,
 * not a runtime surprise. A middleware that always answers the request — a
 * rejection, a cache hit — therefore still declares `$next` and simply never
 * calls it. That is one unused parameter, and it buys a contract the language
 * actually enforces; the alternative is an interface whose signature every
 * implementation is allowed to contradict.
 *
 * The return type is likewise non-negotiable in both directions: PHP permits no
 * loosening at all, so a class declaring `: mixed` or no return type does not
 * satisfy this interface, and one returning `null` cannot implement it at all.
 * The pipeline's "handler returned nothing" diagnosis is therefore unreachable
 * for anything that implements this interface — it exists for a destination
 * closure, which the compiler does not check. See
 * {@see \Framework\Http\Middleware\Pipeline::toResponse()}.
 *
 * ## The parameter names are part of this contract
 *
 * An implementation **must** name its parameters `$request` and `$next`. The
 * pipeline passes both by name, because the container it invokes the middleware
 * through matches an override against the parameter's own name and has no
 * positional or by-type fallback — so a middleware that renames either fails at
 * runtime with an unresolvable-dependency error rather than being wired up some
 * other way. PHP cannot express this constraint, which is why it is documented
 * here and pinned by a test.
 */
interface Middleware
{
    /**
     * Handle the request, optionally delegating to $next.
     *
     * Return any ResponseInterface to short-circuit: the pipeline stops there
     * and nothing further in the stack — including the destination — runs.
     *
     * @param Closure(Request): ResponseInterface $next
     */
    public function handle(Request $request, Closure $next): ResponseInterface;
}
