<?php
declare(strict_types=1);

/**
 * The WordPress function surface the framework touches.
 *
 * Every stub records into (or reads from) Tests\Support\WpStub, so a test can
 * assert *that* a hook was registered and then fire it deliberately with
 * WpStub::doAction() — rather than the framework ever running WordPress's real
 * dispatch, which does not exist here.
 *
 * Only functions the framework actually calls are defined. Adding a stub for a
 * function nothing calls is a stub that will silently drift.
 */

use Tests\Support\WpStub;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}

if (!function_exists('add_action')) {
    /**
     * @param callable $callback
     */
    function add_action(string $tag, $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        WpStub::$actions[$tag][] = $callback;

        return true;
    }
}

if (!function_exists('add_filter')) {
    /**
     * @param callable $callback
     */
    function add_filter(string $tag, $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        return add_action($tag, $callback, $priority, $acceptedArgs);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return WpStub::$currentUserCan;
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return WpStub::$userLoggedIn;
    }
}

if (!function_exists('rest_authorization_required_code')) {
    function rest_authorization_required_code(): int
    {
        return is_user_logged_in() ? 403 : 401;
    }
}

if (!function_exists('register_rest_route')) {
    /**
     * @param array<string, mixed>|array<int, array<string, mixed>> $args
     */
    function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
    {
        // WordPress normalizes a single argument set into a list before storing
        // it; mirroring that here keeps assertions in the tests simple.
        if (isset($args['callback']) || isset($args['methods'])) {
            $args = [$args];
        }

        foreach ($args as $registration) {
            WpStub::$registeredRoutes[$namespace][] = ['route' => $route] + $registration;
        }

        return true;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_normalize_path')) {
    function wp_normalize_path(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool
    {
        return is_dir($target) || mkdir($target, 0755, true);
    }
}

if (!function_exists('get_option')) {
    /**
     * @param mixed $default
     * @return mixed
     */
    function get_option(string $option, $default = false)
    {
        return WpStub::$options[$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    /**
     * @param mixed $value
     */
    function update_option(string $option, $value, bool $autoload = true): bool
    {
        WpStub::$options[$option] = $value;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        unset(WpStub::$options[$option]);

        return true;
    }
}

// ------------------------------------------------------------------- HTTP layer

if (!function_exists('get_current_user_id')) {
    /**
     * On a real install these two always agree — `is_user_logged_in()` is a
     * thin wrapper over the same current-user global, and WordPress sets it from
     * the auth cookie before any hook runs. The stub keeps them as separate
     * fields so a test can stage a token flow where the cookie is absent, so a
     * test that wants "logged in the ordinary way" sets `$userLoggedIn` and this
     * reports a plausible id rather than 0.
     *
     * The default is 1, not 0, because 0 means "nobody" — and a logged-in
     * request reporting nobody is a state WordPress cannot reach.
     */
    function get_current_user_id(): int
    {
        if (WpStub::$currentUserId !== 0) {
            return WpStub::$currentUserId;
        }

        return WpStub::$userLoggedIn ? 1 : 0;
    }
}

if (!function_exists('wp_set_current_user')) {
    /**
     * Establishes the current user, as WordPress does.
     *
     * The stub deliberately does *not* make is_user_logged_in() agree: the real
     * is_user_logged_in() checks the auth cookie, and WordPress's own
     * `determine_current_user` filter chain — which is where a token
     * implementation hooks in — sets the current user first. A test asserting a
     * token flow sets both fields, which is what really happens on a request
     * that authenticated.
     */
    function wp_set_current_user(int $userId): void
    {
        WpStub::$currentUserId = $userId;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = '-1'): string
    {
        return WpStub::issueNonce($action);
    }
}

if (!function_exists('wp_verify_nonce')) {
    /**
     * @return int|false 1 or 2 for a valid nonce, false otherwise.
     */
    function wp_verify_nonce(string $nonce, string $action = '-1')
    {
        if (!isset(WpStub::$nonces[$nonce])) {
            return false;
        }

        return WpStub::$nonces[$nonce] === $action ? 1 : false;
    }
}

if (!function_exists('wp_login_url')) {
    function wp_login_url(string $redirect = ''): string
    {
        $base = WpStub::$loginUrl ?? 'http://example.test/wp-login.php';

        if ($redirect === '') {
            return $base;
        }

        return $base . (str_contains($base, '?') ? '&' : '?') . 'redirect_to=' . rawurlencode($redirect);
    }
}

if (!function_exists('wp_safe_redirect')) {
    /**
     * @param array<string, mixed> $args
     *
     * @throws RuntimeException On a non-3xx status, mirroring WordPress: the real
     *                          wp_safe_redirect() -> wp_redirect() calls wp_die()
     *                          there, which ends the request. A stub that coerced
     *                          the status to 302 instead would let a RedirectResponse
     *                          with a bad status pass a test while producing an
     *                          error page in production.
     */
    function wp_safe_redirect(string $location, int $status = 302, string $xRedirectBy = 'WordPress'): bool
    {
        if ($status < 300 || 399 < $status) {
            WpStub::$died[] = [
                'message' => 'HTTP redirect status code must be a redirection code, 3xx.',
                'title' => '',
                'args' => [],
            ];

            throw new RuntimeException('wp_die(): HTTP redirect status code must be a redirection code, 3xx.');
        }

        WpStub::$redirects[] = ['url' => $location, 'status' => $status];

        return true;
    }
}

if (!function_exists('wp_send_json')) {
    /**
     * @param mixed $data
     */
    function wp_send_json($data, int $statusCode = 200, int $flags = 0): void
    {
        WpStub::$jsonResponses[] = ['data' => $data, 'status' => $statusCode];
    }
}

if (!function_exists('set_transient')) {
    /**
     * @param mixed $value
     */
    function set_transient(string $transient, $value, int $expiration = 0): bool
    {
        WpStub::$transients[$transient] = $value;
        WpStub::$transientWrites[] = [
            'key' => $transient,
            'value' => $value,
            'expiration' => $expiration,
        ];

        return true;
    }
}

if (!function_exists('get_transient')) {
    /**
     * @return mixed
     */
    function get_transient(string $transient)
    {
        return WpStub::$transients[$transient] ?? false;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        unset(WpStub::$transients[$transient]);

        return true;
    }
}

// setcookie() is NOT stubbed here. It is a PHP builtin, so a global function
// declaration would be a fatal and the function_exists() guard above would
// silently skip it — see tests/stubs/builtins.php, which defines it in the
// namespace RedirectResponse is compiled under, which is where an unqualified
// call actually resolves.

if (!function_exists('status_header')) {
    function status_header(int $statusCode, string $description = ''): void
    {
        WpStub::$statusHeaders[] = $statusCode;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html($text);
    }
}

if (!function_exists('wp_die')) {
    /**
     * @param array<string, mixed> $args
     *
     * @throws RuntimeException Always — the real function ends the request, and a
     *                          stub that returned would let code after a wp_die()
     *                          run, which never happens in production.
     */
    function wp_die(string $message = '', string $title = '', array $args = []): void
    {
        WpStub::$died[] = ['message' => $message, 'title' => $title, 'args' => $args];

        throw new RuntimeException('wp_die(): ' . $message);
    }
}

// ------------------------------------------------------------- admin menu layer

if (!function_exists('add_menu_page')) {
    /**
     * @param callable $callback
     */
    function add_menu_page(
        string $pageTitle,
        string $menuTitle,
        string $capability,
        string $menuSlug,
        $callback = null,
        string $iconUrl = '',
        ?int $position = null
    ): string {
        WpStub::$menuPages[] = [
            'page_title' => $pageTitle,
            'menu_title' => $menuTitle,
            'capability' => $capability,
            'menu_slug' => $menuSlug,
            'callback' => $callback,
            'icon_url' => $iconUrl,
            'position' => $position,
        ];

        // WordPress returns the hook suffix the page is registered under, and a
        // caller uses it to enqueue assets for that one screen. The stub composes
        // the same shape ("toplevel_page_{slug}") so a test can assert on it.
        return 'toplevel_page_' . $menuSlug;
    }
}

if (!function_exists('add_submenu_page')) {
    /**
     * @param callable $callback
     */
    function add_submenu_page(
        string $parentSlug,
        string $pageTitle,
        string $menuTitle,
        string $capability,
        string $menuSlug,
        $callback = null,
        ?int $position = null
    ): string {
        WpStub::$submenuPages[] = [
            'parent_slug' => $parentSlug,
            'page_title' => $pageTitle,
            'menu_title' => $menuTitle,
            'capability' => $capability,
            'menu_slug' => $menuSlug,
            'callback' => $callback,
            'position' => $position,
        ];

        return $parentSlug . '_page_' . $menuSlug;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = '', string $scheme = 'admin'): string
    {
        $base = WpStub::$adminUrl ?? 'http://example.test/wp-admin/';

        return $path === '' ? $base : rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_nonce_field')) {
    /**
     * @param bool $referer
     */
    function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display = true): string
    {
        // `wp_create_nonce()` rather than a literal, so a form rendered by a view
        // under test carries a nonce that genuinely verifies — a hardcoded string
        // would make VerifyAdminNonce look like it worked when it did not.
        $field = sprintf(
            '<input type="hidden" id="%s" name="%s" value="%s" />',
            $name,
            $name,
            wp_create_nonce($action)
        );

        if ($display) {
            echo $field;
        }

        return $field;
    }
}

// ------------------------------------------------------------- front-end layer

if (!function_exists('is_admin')) {
    function is_admin(): bool
    {
        return WpStub::$isAdmin;
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = '', ?string $scheme = null): string
    {
        $base = WpStub::$homeUrl ?? 'http://example.test';

        return $path === '' ? $base : rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('wp_parse_url')) {
    /**
     * @return array<string, int|string>|string|int|false|null
     */
    function wp_parse_url(string $url, int $component = -1)
    {
        return parse_url($url, $component);
    }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit(string $value): string
    {
        return rtrim($value, '/\\');
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $value): string
    {
        return rtrim($value, '/\\') . '/';
    }
}
