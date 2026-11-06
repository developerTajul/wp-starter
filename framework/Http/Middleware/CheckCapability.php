<?php
declare(strict_types=1);

namespace Framework\Http\Middleware;

use Closure;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;

/**
 * Rejects the request unless the current user holds every required capability.
 *
 * The parameterized counterpart to {@see Authorize}. Both check the same thing
 * through the same `current_user_can()` call; they differ in **where the
 * capability comes from**, and that difference is the whole reason both exist:
 *
 *     // Configured once, in the class — reusable, and reusable *safely*.
 *     final class RequireLeadAccess extends Authorize
 *     {
 *         public function __construct()
 *         {
 *             parent::__construct(LeadController::CAPABILITY);
 *         }
 *     }
 *
 *     // Configured per route, at the call site.
 *     ->middleware('capability:' . LeadController::CAPABILITY)
 *
 * ## Which to reach for
 *
 * `Authorize` when the capability is a property of the *class's meaning* — a
 * guard that is always the same guard, named once, and whose name is what a
 * reader looks for. A subclass like the one above is self-documenting.
 *
 * This one when the capability is a property of the *route* — several routes
 * that differ only in which capability they need, where a subclass each would be
 * boilerplate, and where the parameter sitting next to the route is easier to
 * audit than a class defined elsewhere.
 *
 * ## Why the parameters are read from `$arguments`, not the constructor
 *
 * The pipeline splits `'capability:manage_options'` and passes the tail as extra
 * arguments; the constructor is not involved, because the middleware is built by
 * the container before its parameters are known. See
 * {@see \Framework\Http\Middleware\Pipeline::parseEntry()}.
 *
 * ## All of them, or none
 *
 * Several capabilities may be given — `'capability:edit_posts,edit_others_posts'`
 * — and **every one must be held**. That is deliberate and matches
 * {@see Authorize}, and it is the opposite of WordPress's own
 * `authorize() => 'edit_posts'` on a REST route, which accepts an array and
 * grants access if **any** is held. Passing a list here expecting OR semantics
 * would silently be stricter than intended, which fails closed and is therefore
 * the safe direction to be wrong in.
 *
 * ## No capability is refused, unlike Authorize
 *
 * {@see Authorize} logs and *allows* when it was constructed with no
 * capabilities, on the grounds that denying would make a misconfiguration
 * invisible. That reasoning does not transfer here. An empty `Authorize` is a
 * class someone wrote and can go and read; an empty parameter is
 * `'capability:'` in a routes file, which is a typo with nothing to inspect — and
 * a guard that silently allows every request because of one is exactly the
 * failure this middleware exists to prevent. So it is a 403, and the message
 * names the mistake.
 */
class CheckCapability implements Middleware
{
    /**
     * @param list<string> $arguments The capability or capabilities, from the
     *                                route's `'capability:...'` parameter.
     */
    public function handle(Request $request, Closure $next, string ...$arguments): ResponseInterface
    {
        $capabilities = self::capabilities($arguments);

        if ($capabilities === []) {
            return $this->reject($request, sprintf(
                'This route requires a capability but none was configured. '
                . 'Write it as [capability:manage_options] — the part after the colon is missing.'
            ));
        }

        foreach ($capabilities as $capability) {
            if (!$this->holds($capability)) {
                return $this->reject(
                    $request,
                    'You are not allowed to perform this action.'
                );
            }
        }

        return $next($request);
    }

    /**
     * The capabilities named by the route's parameter.
     *
     * Split on commas so one entry can carry several, and blanks dropped so
     * `'capability:manage_options,'` behaves as the single capability it plainly
     * means rather than as a second, empty one.
     *
     * @param list<string> $arguments
     * @return list<string>
     */
    private static function capabilities(array $arguments): array
    {
        $capabilities = [];

        foreach ($arguments as $argument) {
            foreach (explode(',', $argument) as $capability) {
                $capability = trim($capability);

                if ($capability !== '') {
                    $capabilities[] = $capability;
                }
            }
        }

        return $capabilities;
    }

    /**
     * Whether the current user holds one capability.
     *
     * Separated from the loop so a subclass has a seam to override — for a
     * capability this middleware should always grant, or for a test that needs to
     * answer differently per capability without reaching into WordPress.
     */
    protected function holds(string $capability): bool
    {
        if (!function_exists('current_user_can')) {
            // Failing closed, for the same reason {@see VerifyAdminNonce} does: a
            // capability check that cannot run has authorised nothing.
            return false;
        }

        return current_user_can($capability);
    }

    /**
     * Answer a rejected request in the shape the client asked for.
     *
     * A headless caller gets a 403 document it can parse. A browser gets a
     * `wp_die()` page — WordPress's own "this request cannot proceed" mechanism —
     * rather than a bare JSON body rendered as text, which is what a form post
     * would otherwise show. The status is 403 either way: a permission failure
     * that reached this middleware has already been authenticated (or is on a
     * route where authentication is a separate layer), so the honest code is
     * "forbidden", not "unauthenticated". Routes that want the 401-vs-403
     * distinction {@see Authorize} draws should use `Authenticate` ahead of this
     * one and let it answer for anonymous callers.
     */
    private function reject(Request $request, string $message): ResponseInterface
    {
        if ($request->expectsJson()) {
            return new JsonResponse(
                ['error' => 'forbidden', 'message' => $message],
                ['status_code' => 403]
            );
        }

        if (function_exists('wp_die') && function_exists('esc_html')) {
            wp_die(
                esc_html($message),
                esc_html__('Sorry, you are not allowed to access this page.', 'wp-mini-laravel'),
                ['response' => 403]
            );
        }

        // No WordPress: nothing to render a page with, so the JSON body is the
        // only honest answer. Reached by a test or a headless context, never by a
        // real browser request.
        return new JsonResponse(
            ['error' => 'forbidden', 'message' => $message],
            ['status_code' => 403]
        );
    }
}
