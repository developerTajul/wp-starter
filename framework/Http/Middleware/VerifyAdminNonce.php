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
 * WordPress's own `check_admin_referer()` does this, and then `wp_die()`s. That
 * is fine for a wp-admin screen and wrong for anything else: it produces an HTML
 * error page for a JSON client, and it makes the failure untestable because the
 * process ends. This middleware answers with a response instead, so the caller
 * decides what a rejection looks like.
 *
 * Which nonce, and why it is a parameter
 *
 * `wp_verify_nonce()` needs the action the nonce was created for, and WordPress
 * puts it in `wp_nonce_field()`'s second argument, not in the HTML it emits.
 * There is no way to recover it from the request, so it has to be configured.
 * The default action is `'-1'`. That is the value `wp_nonce_field()` bakes into
 * a form when no action is given, so **a standard WordPress form post satisfies
 * the default with no change** - and a nonce minted for a *specific* action does
 * not, which is the behaviour you want from a middleware that a controller
 * reaches for without thinking about the action.
 *
 * `-1` is *not* a wildcard. It is a specific action, and it happens to be the
 * one most nonces in a WordPress install are created with. Verified against a
 * real install: `wp_create_nonce('save_lead')` fails both `wp_verify_nonce($n)`
 * and a no-action `check_admin_referer()`. If you need a nonce to be accepted
 * regardless of the action it was created under, this middleware does not
 * offer it - pass the action you minted with instead.
 *
 *     $router->post('leads', LeadController::class, 'store')
 *         ->middleware(VerifyAdminNonce::class);
 *
 *     // Or, checked against a specific action:
 *     final class VerifyLeadForm extends VerifyAdminNonce
 *     {
 *         public function __construct()
 *         {
 *             parent::__construct('save_lead');
 *         }
 *     }
 *
 * **{@see VerifyNonce} is the same check configured per route** -
 * `->middleware('nonce:save_lead')` - and is what this class's own docblock above
 * would otherwise be teaching by subclass. Reach for this one when the action is
 * a property of the class's meaning (a guard that is always the same guard, named
 * once); reach for `VerifyNonce` when it is a property of the route.
 *
 * Passing `'-1'` explicitly means the same as the default. Passing `null`
 * changes nothing either, and is accepted only so the constructor reads clearly:
 * WordPress's `$action` parameter defaults to the integer `-1`, and since
 * `(string) -1 === '-1'` and `wp_hash()` casts before hashing, the omitted and
 * explicit forms hash identical input. There is no "verify any action" mode.
 */
class VerifyAdminNonce implements Middleware
{
    /**
     * The request parameter the nonce arrives in.
     *
     * `_wpnonce` is what `wp_nonce_field()` emits, so a standard WordPress form
     * post needs no change to satisfy this middleware.
     */
    public const FIELD = '_wpnonce';

    public function __construct(
        private readonly ?string $action = '-1',
        private readonly string $field = self::FIELD
    ) {
    }

    public function handle(Request $request, Closure $next): ResponseInterface
    {
        $nonce = $request->input($this->field);

        // A nonce is always a string. An array here is either a mistake or an
        // attempt to make wp_verify_nonce() behave unexpectedly, and neither
        // deserves the benefit of the doubt.
        if (!is_string($nonce) || $nonce === '') {
            return $this->reject($request, 'The security check is missing from this request.');
        }

        if (!function_exists('wp_verify_nonce')) {
            // Failing closed. A middleware whose whole job is a security check
            // must not degrade into a pass-through when the function it needs is
            // absent - that would make a broken install look like a working one.
            return $this->reject($request, 'The security check could not be performed.');
        }

        // `wp_verify_nonce()` returns 1 or 2 for a valid nonce (2 when it was
        // created in the current 12-hour window), and false otherwise. It is
        // compared against false rather than tested for truthiness so that the
        // falsy-but-valid cases PHP could produce are not treated as failures.
        // Omitting the action is safe rather than sloppy: WordPress's parameter
        // default is the integer `-1`, `(string) -1 === '-1'`, and `wp_hash()`
        // casts before hashing - so the default and `check_admin_referer()`'s
        // no-action path hash identical input and accept the same nonces.
        // Verified against a real install, not inferred.
        $verified = $this->action === null
            ? wp_verify_nonce($nonce)
            : wp_verify_nonce($nonce, $this->action);

        if ($verified === false) {
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

        // wp_die() is absent - a non-WordPress context, or a filtered install.
        // Falling through to $next() would silently drop the check, so the only
        // safe answer is to refuse, however plainly. Escaping is skipped for the
        // same reason: a message this class composes is not user input, and
        // esc_html() is WordPress's, not PHP's.
        return new JsonResponse(
            ['error' => 'invalid_nonce', 'message' => $message],
            ['status_code' => 403]
        );
    }
}
