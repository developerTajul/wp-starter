<?php
declare(strict_types=1);

namespace Framework\Http\Middleware;

use Closure;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;

/**
 * Rejects a request whose CSRF nonce is missing or stale.
 *
 * The parameterized counterpart to {@see VerifyAdminNonce}. Both verify with the
 * same `wp_verify_nonce()` call; they differ in **where the action comes from**:
 *
 *     // Configured once, in the class.
 *     final class VerifyLeadForm extends VerifyAdminNonce
 *     {
 *         public function __construct()
 *         {
 *             parent::__construct(LeadController::STORE_ACTION);
 *         }
 *     }
 *
 *     // Configured per route, at the call site.
 *     ->middleware('nonce:' . LeadController::STORE_ACTION)
 *
 * The trade-off is the same as {@see CheckCapability}'s, and the failure mode is
 * worth restating because it is quiet here rather than loud: a form that mints
 * its nonce with one action and a middleware configured with another rejects
 * every submission, and the only symptom is "your session has expired" on a form
 * nobody changed. Naming the action in one class makes the two impossible to
 * disagree; naming it at the route makes the pairing visible in the routes file
 * but spreads the value across two files that must still match.
 *
 * ## Parameters
 *
 * The first parameter is the nonce **action**. The optional second is the
 * **field name**, which defaults to `_wpnonce` — what `wp_nonce_field()` emits,
 * so a standard WordPress form post needs no change.
 *
 *     'nonce:leads_store'                    // field _wpnonce, action leads_store
 *     'nonce:leads_store,my_custom_nonce'    // a form that names its own field
 *
 * A missing action is **refused**, not defaulted. `VerifyAdminNonce` defaults to
 * `'-1'` because that is what `wp_nonce_field()` bakes into a form given no
 * action, and it is a legitimate configuration. Here an omitted action is a
 * routes-file typo — `'nonce:'` — and quietly verifying against `-1` would
 * accept a nonce minted for a *different* purpose, or reject every real
 * submission, with nothing to indicate which. See {@see VerifyAdminNonce} for why
 * `-1` is a specific action and not a wildcard.
 *
 * ## Where the nonce is read from
 *
 * `$request->input()`, which sees the query string, the form body and a JSON
 * document alike. That is what makes a nonce acceptable from a `fetch()` that
 * sends `X-WP-Nonce` — no, that header is *not* read here: WordPress's REST
 * layer consumes it, and this middleware guards the web surfaces, where the
 * nonce travels as a form field. A headless client posting a form reaches this
 * through the body.
 */
class VerifyNonce implements Middleware
{
    /**
     * The default request parameter the nonce arrives in.
     *
     * `_wpnonce` is what `wp_nonce_field()` emits, so a standard WordPress form
     * post needs no change to satisfy this middleware.
     */
    public const FIELD = VerifyAdminNonce::FIELD;

    /**
     * @param list<string> $arguments The action, optionally followed by the field.
     */
    public function handle(Request $request, Closure $next, string ...$arguments): ResponseInterface
    {
        $action = trim($arguments[0] ?? '');

        if ($action === '') {
            return $this->reject($request, sprintf(
                'This route requires a nonce but no action was configured. '
                . 'Write it as [nonce:%s] — the part after the colon is missing.',
                'leads_store'
            ));
        }

        $field = trim($arguments[1] ?? '') ?: self::FIELD;

        $nonce = $request->input($field);

        // A nonce is always a string. An array here is either a mistake or an
        // attempt to make wp_verify_nonce() behave unexpectedly, and neither
        // deserves the benefit of the doubt.
        if (!is_string($nonce) || $nonce === '') {
            return $this->reject($request, 'The security check is missing from this request.');
        }

        if (!function_exists('wp_verify_nonce')) {
            // Failing closed. A middleware whose whole job is a security check
            // must not degrade into a pass-through when the function it needs is
            // absent — that would make a broken install look like a working one.
            return $this->reject($request, 'The security check could not be performed.');
        }

        // `wp_verify_nonce()` returns 1 or 2 for a valid nonce (2 when it was
        // created in the current 12-hour window), and false otherwise. Compared
        // against false rather than tested for truthiness so the falsy-but-valid
        // cases PHP could produce are not treated as failures.
        if (wp_verify_nonce($nonce, $action) === false) {
            return $this->reject(
                $request,
                'Your session has expired. Please reload the page and try again.'
            );
        }

        return $next($request);
    }

    /**
     * Answer a rejected request in the shape the client asked for.
     *
     * A form post gets `wp_die()`, which is WordPress's own mechanism for "this
     * request cannot proceed" and renders the site's error page. A JSON client
     * gets a 403 document, because an HTML error page inside a `fetch()` is a
     * parse failure on the client and tells the developer nothing.
     */
    private function reject(Request $request, string $message): ResponseInterface
    {
        if ($request->expectsJson()) {
            return new JsonResponse(
                ['error' => 'invalid_nonce', 'message' => $message],
                ['status_code' => 403]
            );
        }

        if (function_exists('wp_die') && function_exists('esc_html')) {
            wp_die(
                esc_html($message),
                esc_html__('Security check failed', 'wp-mini-laravel'),
                ['response' => 403]
            );
        }

        // wp_die() is absent — a non-WordPress context, or a filtered install.
        // Falling through to $next() would silently drop the check, so the only
        // safe answer is to refuse, however plainly.
        return new JsonResponse(
            ['error' => 'invalid_nonce', 'message' => $message],
            ['status_code' => 403]
        );
    }
}
