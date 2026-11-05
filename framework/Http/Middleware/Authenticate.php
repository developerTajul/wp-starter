<?php
declare(strict_types=1);

namespace Framework\Http\Middleware;

use Closure;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\RedirectResponse;

/**
 * Rejects the request unless a user is logged in.
 *
 * The cookie case is straightforward: `is_user_logged_in()` reads the auth
 * cookie, WordPress has already validated it by the time a hook runs, and the
 * answer is trustworthy.
 *
 * ## The headless case
 *
 * `is_user_logged_in()` is false for a request carrying an application password
 * or a JWT, so a headless client would be rejected by a middleware whose whole
 * purpose is to admit authenticated callers. Token authentication is
 * deliberately **not** implemented here — a framework has no business deciding
 * how tokens are issued, signed or stored, and getting it wrong is a
 * vulnerability, not a bug — so this class offers the two hooks a real
 * implementation needs:
 *
 *  1. **{@see resolveUser()}** — subclass it to verify a token from
 *     `$request->header('authorization')` and return a user id. The return value
 *     is fed to `wp_set_current_user()`, so `current_user_can()` and
 *     `Authorize` work downstream without knowing which scheme was used.
 *  2. **`rest_authentication_errors`** — the WordPress-sanctioned route. A
 *     plugin that handles tokens hooks that filter, and by the time a REST route
 *     runs `is_user_logged_in()` already reports true. On a REST route that is
 *     the simplest correct answer, and it is what the `Authorize` docblock
 *     recommends for bearer tokens.
 *
 * ## Why the redirect
 *
 * A browser navigating to a protected admin page needs to be sent to the login
 * form, not shown a 401 body — so the failure is a redirect unless the client
 * asked for JSON. `wp_login_url()` carries a `redirect_to` back to the page that
 * was requested, so the user lands where they were going.
 */
class Authenticate implements Middleware
{
    private bool $redirectToLogin;

    public function __construct(bool $redirectToLogin = true)
    {
        $this->redirectToLogin = $redirectToLogin;
    }

    public function handle(Request $request, Closure $next): ResponseInterface
    {
        if ($this->isAuthenticated($request)) {
            return $next($request);
        }

        return $this->unauthenticated($request);
    }

    /**
     * Resolve the user this request is authenticated as, or 0 for none.
     *
     * Override to support a token scheme. The id returned is passed to
     * `wp_set_current_user()`, so the rest of the request — including
     * `Authorize` and any WordPress API call — sees that user as current.
     *
     * The default is the cookie path: `is_user_logged_in()` reflects whatever
     * WordPress's `determine_current_user` chain has already established, which
     * on a REST request is also where a plugin hooking `rest_authentication_errors`
     * would have put it.
     *
     * **The return value is authoritative.** Returning 0 refuses the request even
     * when a valid auth cookie is present, so a subclass that checked a token and
     * found it forged is not silently rescued by a stale session. That is the
     * behaviour a token scheme needs — otherwise "invalid token" quietly degrades
     * to "use whatever session PHP has".
     */
    protected function resolveUser(Request $request): int
    {
        if (!function_exists('is_user_logged_in') || !is_user_logged_in()) {
            return 0;
        }

        return function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    }

    /**
     * Whether the request is authenticated, establishing the current user as a
     * side effect when it is.
     */
    private function isAuthenticated(Request $request): bool
    {
        $userId = $this->resolveUser($request);

        if ($userId <= 0) {
            return false;
        }

        // The cookie path has its current user set already by WordPress, so this
        // is a no-op there; it matters for a subclass that authenticated by
        // another scheme, where it is what makes `current_user_can()` and every
        // WordPress API call downstream see the right user.
        if (function_exists('wp_set_current_user')) {
            wp_set_current_user($userId);
        }

        return true;
    }

    private function unauthenticated(Request $request): ResponseInterface
    {
        // JSON first: a headless client must never receive a 302 to an HTML
        // login form, which it would follow and then fail to parse.
        if ($request->expectsJson()) {
            return new JsonResponse(
                ['error' => 'unauthenticated', 'message' => 'Authentication is required.'],
                ['status_code' => 401]
            );
        }

        if ($this->redirectToLogin) {
            // The request is handed over so the flash cookie's `Secure` flag is
            // decided from the headers rather than `$_SERVER['HTTPS']` — which is
            // unset behind a TLS-terminating proxy, where the cookie would
            // otherwise be issued without it.
            return new RedirectResponse($this->loginUrl($request), 302, $request);
        }

        return new JsonResponse(
            ['error' => 'unauthenticated', 'message' => 'Authentication is required.'],
            ['status_code' => 401]
        );
    }

    /**
     * The login URL, carrying the originally requested page.
     *
     * The target is qualified into an absolute URL before it is handed over,
     * because `wp_safe_redirect()` validates against `allowed_redirect_hosts`:
     * a bare path such as `/members` is passed through only because WordPress
     * treats a path with no host as same-site. Making it absolute keeps that
     * true when the request arrived on a host WordPress does not consider its
     * own — behind a proxy, or on a domain alias — where the relative form would
     * be rejected and the redirect would silently do nothing.
     *
     * The target comes from the Request rather than `$_SERVER`, which is what
     * keeps this the only class that touches a superglobal.
     */
    private function loginUrl(Request $request): string
    {
        $target = $this->absoluteUrl($request);

        if (function_exists('wp_login_url')) {
            return wp_login_url($target);
        }

        // No WordPress. A relative URL cannot be an open redirect, which is the
        // right failure mode for a fallback nobody should reach.
        return '/wp-login.php?redirect_to=' . rawurlencode($request->uri());
    }

    /**
     * The requested URI as an absolute URL on the current host.
     *
     * Built from the Host header rather than `home_url()`, because that is what
     * the client actually asked for and therefore what they should return to.
     */
    private function absoluteUrl(Request $request): string
    {
        $scheme = $request->isSecure() ? 'https' : 'http';
        $host = $request->header('host');

        if ($host === null || $host === '') {
            // No Host header: a malformed request. The relative path is the safe
            // answer, since inventing a host could redirect somewhere unintended.
            return $request->uri();
        }

        return $scheme . '://' . $host . $request->uri();
    }
}
