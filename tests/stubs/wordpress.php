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
        // `acceptedArgs` is stored with the callback rather than dropped, because
        // WordPress's default of 1 is load-bearing: a listener declared
        // `function ($identifier)` on a hook fired with three arguments must be
        // called with one. Discarding it here would let a stub call every
        // listener with every argument, and a test could then not tell a
        // correctly-declared listener from one that would fatal in production.
        WpStub::$actions[$tag][] = [
            'callback' => $callback,
            'acceptedArgs' => $acceptedArgs,
        ];

        return true;
    }
}

if (!function_exists('do_action')) {
    /**
     * Fire a hook, calling every callback registered under it.
     *
     * Mirrors WordPress's own semantics that matter here: callbacks run in
     * registration order, each receives the arguments passed, and a callback
     * registered with a smaller `acceptedArgs` than were supplied is called with
     * only that many. The last two are the ones a stub could plausibly get wrong
     * and thereby make a real bug invisible, so they are modelled rather than
     * reduced to "call everything with everything".
     *
     * `$acceptedArgs` is stored alongside the callback by {@see add_action()}
     * because WordPress's default of 1 is load-bearing: a listener declared
     * `function ($identifier)` on a hook fired with three arguments is called
     * with one, not three.
     *
     * @param mixed ...$args
     */
    function do_action(string $tag, ...$args): void
    {
        WpStub::$fired[] = $tag;

        foreach (WpStub::$actions[$tag] ?? [] as $entry) {
            $callback = $entry['callback'] ?? $entry;
            $accepted = $entry['acceptedArgs'] ?? null;

            if ($accepted === null || count($args) <= $accepted) {
                $callback(...$args);

                continue;
            }

            $callback(...array_slice($args, 0, $accepted));
        }
    }
}

if (!function_exists('did_action')) {
    function did_action(string $tag): int
    {
        return count(array_filter(WpStub::$fired, static fn (string $fired): bool => $fired === $tag));
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
    /**
     * Answers per capability, not blanket.
     *
     * A stub that returned the same answer whatever it was asked is how two
     * guards asking for *different* capabilities stayed green while disagreeing:
     * `list_users` and `edit_others_posts` were both "yes", so the middleware
     * admitted a caller the controller then silently showed nothing to. Nothing
     * in the suite could see it, because the suite never let the two answers
     * differ.
     *
     * So the stub records what it was asked and consults a per-capability map
     * first. `$currentUserCan` stays as the fallback for the many tests that do
     * not care which capability is checked — a test that *does* care sets
     * {@see WpStub::$capabilities} and gets a real distinction.
     */
    function current_user_can(string $capability): bool
    {
        WpStub::$capabilitiesAsked[] = $capability;

        return WpStub::$capabilities[$capability] ?? WpStub::$currentUserCan;
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

// -------------------------------------------------------------- plugin lifecycle

if (!function_exists('register_activation_hook')) {
    /**
     * Record the activation callback, as WordPress does.
     *
     * **The basename rule is modelled, not skipped.** WordPress's real
     * `register_activation_hook()` computes `plugin_basename( $file )` and
     * silently returns — registering nothing — when the result contains no
     * directory separator, which is how it rejects a path outside
     * `WP_PLUGIN_DIR`. A stub that recorded unconditionally would let an entry
     * file pass `__DIR__ . '/wp-mini-laravel.php'` (no separator after
     * basename-ing) and look correct here while registering no hook at all in
     * production. The one rule this stub enforces is the one that fails quietly.
     *
     * @param string $file Absolute path to the plugin's entry file.
     */
    function register_activation_hook(string $file, $callback): void
    {
        $basename = plugin_basename($file);

        if (!str_contains($basename, '/')) {
            return;
        }

        WpStub::$activationHooks[$basename] = $callback;
    }
}

if (!function_exists('register_deactivation_hook')) {
    /**
     * Record the deactivation callback. Same basename rule as activation.
     *
     * @param string $file Absolute path to the plugin's entry file.
     */
    function register_deactivation_hook(string $file, $callback): void
    {
        $basename = plugin_basename($file);

        if (!str_contains($basename, '/')) {
            return;
        }

        WpStub::$deactivationHooks[$basename] = $callback;
    }
}

if (!function_exists('register_uninstall_hook')) {
    /**
     * Record the uninstall callback.
     *
     * Stubbed even though the plugin does not call it, and that is the point:
     * the plugin deliberately uses `uninstall.php` instead, because this
     * function stores its callback in the `uninstall_plugins` option — a
     * registration kept in the database being deleted. A test asserts it is
     * *not* called, and it cannot assert that against an undefined function.
     *
     * @param string $file Absolute path to the plugin's entry file.
     */
    function register_uninstall_hook(string $file, $callback): void
    {
        WpStub::$uninstallHooks[plugin_basename($file)] = $callback;
    }
}

if (!function_exists('plugin_basename')) {
    /**
     * The plugin-relative path, as WordPress computes it.
     *
     * ## Why `WP_PLUGIN_DIR` is defined here, and why it is the plugin itself
     *
     * The stub layer stands in for a WordPress install, and in one the plugins
     * directory is *the parent of the plugin's own directory* — every plugin
     * lives somewhere under `WP_PLUGIN_DIR`, which is what makes a plugin's
     * basename look like `wp-mini-laravel/wp-mini-laravel.php` rather than a bare
     * filename. Core's `register_activation_hook()` depends on that shape: it
     * computes `plugin_basename( $file )` and returns without registering
     * anything when the result has no directory separator, which is how it
     * refuses a path that is not inside the plugins directory.
     *
     * Getting this wrong is not a subtle failure, it is a *silent* one — the
     * hook simply never registers — and before this constant existed the
     * fallback below stripped the plugin's own directory and produced exactly
     * that bare filename, so every `register_*_hook()` call in the framework
     * resolved to nothing. The tests written against the lifecycle wiring caught
     * it; see {@see \Tests\Unit\PluginLifecycleWiringTest}, which asserts the
     * registered key contains a separator for that reason.
     *
     * Deriving the value rather than hardcoding it keeps the relation intact for
     * anyone who moves the plugin.
     */
    function plugin_basename(string $file): string
    {
        $file = wp_normalize_path($file);
        $root = wp_normalize_path(WP_PLUGIN_DIR);

        if (str_starts_with($file, rtrim($root, '/') . '/')) {
            return ltrim(substr($file, strlen(rtrim($root, '/'))), '/');
        }

        return ltrim($file, '/');
    }
}

// Defined after `plugin_basename()` so the reason for it sits next to the
// function that reads it, and guarded so a test that has already provided one
// wins.
//
// `ABSPATH` above is the *plugin's* root — deliberately, because the entry file
// calls `realpath(ABSPATH . 'wp-admin/includes/plugin.php')` during bootstrap and
// that has to resolve to the stub. That is a different thing from
// `WP_PLUGIN_DIR`, which is the directory the plugin sits *in*, and the two
// cannot be the same value without collapsing a plugin's basename to a bare
// filename — which core treats as "not a plugin" and refuses to register. A real
// install has them one level apart; so does this.
//
// Derived from `ABSPATH` through `rtrim()`, not by counting `dirname()` levels:
// `ABSPATH` is defined with a trailing separator, and `dirname()` ignores it, so
// a level count that reads correctly here would be off by one on a machine where
// the path resolved differently.
if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', dirname(rtrim(ABSPATH, '/\\')) . '/');
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('checked')) {
    /**
     * Echo the `checked` attribute when the two values match, as core does.
     *
     * `$echo` defaults to true because that is core's default and a caller that
     * forgot it would otherwise get a silently missing attribute. Returns the
     * string either way, which is what makes `checked(...)` usable in a test.
     *
     * @param mixed $checked
     * @param mixed $current
     */
    function checked($checked, $current = true, bool $echo = true): string
    {
        $result = (string) $checked === (string) $current ? " checked='checked'" : '';

        if ($echo) {
            echo $result;
        }

        return $result;
    }
}

if (!function_exists('add_settings_field')) {
    /**
     * Record a settings field, as core does.
     *
     * @param callable $callback
     * @param array<string, mixed> $args
     */
    function add_settings_field(
        string $id,
        string $title,
        $callback,
        string $page = 'general',
        string $section = 'default',
        array $args = []
    ): void {
        WpStub::$settingsFields[] = [
            'id' => $id,
            'title' => $title,
            'callback' => $callback,
            'page' => $page,
            'section' => $section,
            'args' => $args,
        ];
    }
}

if (!function_exists('register_setting')) {
    /**
     * Record a registered setting. Nothing calls it — see the comment below.
     *
     * @param array<string, mixed>|string $args
     */
    function register_setting(string $group, string $name, $args = []): void
    {
        WpStub::$registeredSettings[] = ['group' => $group, 'name' => $name, 'args' => $args];
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

if (!function_exists('wp_unslash')) {
    /**
     * Strip the slashes WordPress adds to superglobal input.
     *
     * WordPress runs `wp_magic_quotes()` at bootstrap, so `$_POST['name']` for a
     * typed `O'Brien` really holds `O\'Brien`. The real `wp_unslash()` is
     * `stripslashes_deep()` — recursion included — and this mirrors that, because
     * the recursion is not incidental: a nested `lead[name]` field is slashed in
     * the same pass, and a stub that only handled the flat case would let a
     * middleware pass its test while corrupting every nested value.
     *
     * Typed `mixed` and returns its argument unchanged for a non-string or
     * non-array, exactly as core does.
     *
     * @param mixed $value
     * @return mixed
     */
    function wp_unslash($value)
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('sanitize_text_field')) {
    /**
     * WordPress's single-line text sanitizer, near enough for a test to mean
     * something.
     *
     * The real function strips tags, removes line breaks, collapses runs of
     * whitespace, trims, and drops invalid UTF-8. Reproducing every branch is not
     * the point — this stub exists so `SanitizeInput` is *exercised* rather than
     * silently skipped by its `function_exists()` guard, which would make the
     * middleware look correct while testing nothing at all.
     *
     * The three behaviours kept are the three a test can meaningfully assert and
     * the three a caller is most likely to depend on: tags removed, newlines
     * flattened to spaces, and whitespace collapsed and trimmed. That last one is
     * the one worth knowing about, because it is what changes a multi-line
     * textarea into a single line — see {@see SanitizeInput}'s docblock.
     */
    function sanitize_text_field(string $text): string
    {
        // `strip_tags()` before the whitespace pass, matching core's order: a tag
        // boundary is where the newlines inside a `<script>` block stop mattering.
        $filtered = strip_tags($text);

        // `<` and `>` survive `strip_tags()` when they are not part of a tag;
        // core removes them outright, so a payload like `a < b` cannot become a
        // tag once it is concatenated with something else downstream.
        $filtered = str_replace(['<', '>'], '', $filtered);

        // Line breaks become spaces rather than being deleted, so `a\nb` reads as
        // `a b` and not `ab` — collapsing them to nothing would silently join two
        // words that were separate lines.
        $filtered = preg_replace('/[\r\n\t]+/', ' ', $filtered) ?? $filtered;

        // Runs of spaces collapse, then the ends are trimmed.
        $filtered = preg_replace('/ {2,}/', ' ', $filtered) ?? $filtered;

        return trim($filtered);
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
     * Record a written option.
     *
     * The `$autoload` flag is recorded rather than dropped, because it is a
     * decision callers make deliberately — the migration high-water mark and the
     * uninstall toggle are both written with autoload off, since both are read
     * once and loading them on every page view for the life of the site is
     * waste. A stub that discarded the flag could not tell a caller that passed
     * it from one that forgot.
     *
     * @param mixed $value
     */
    function update_option(string $option, $value, bool $autoload = true): bool
    {
        WpStub::$options[$option] = $value;
        WpStub::$optionAutoload[$option] = $autoload;

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

// --------------------------------------------------------------- object cache
//
// WordPress's object cache is a *per-request* store unless a persistent backend
// (Redis, Memcached) is installed, and `wp_using_ext_object_cache()` is the only
// way to ask which. These stubs model the in-memory half faithfully: a write
// lands in `WpStub::$objectCache`, which `WpStub::reset()` clears — exactly the
// "gone on the next request" behaviour a store that wrongly assumed persistence
// would be caught by.
//
// `$found` is by reference, and that is the whole reason this block is written
// out rather than inlined. `wp_cache_get()` returns `false` for a miss AND
// `false` for a stored `false`; `$found` is the only thing that tells them
// apart. A stub that forgets to write it makes every cache-miss test pass while
// the implementation under it cannot distinguish a cached `false` from nothing
// at all — the precise failure mode the `current_user_can()` stub below warns
// about, and the reason `WpCacheStore` never stores a bare `false`.

if (!function_exists('wp_cache_get')) {
    /**
     * @param string $group
     * @param bool   $found Set to whether the key was present. By reference:
     *                      this is the only signal that separates a stored
     *                      `false` from a miss.
     *
     * @return mixed
     */
    function wp_cache_get(string $key, string $group = '', bool &$found = null)
    {
        $grouped = WpStub::$objectCache[$group] ?? [];

        if (!array_key_exists($key, $grouped)) {
            // Written even on a miss. Leaving `$found` untouched would leave
            // whatever the caller last had in it, which reads as a hit.
            $found = false;

            return false;
        }

        $found = true;

        return $grouped[$key];
    }
}

if (!function_exists('wp_cache_set')) {
    /**
     * @param mixed $value
     * @param int   $expiration Ignored: an object cache with no persistent
     *                          backend has nothing to expire *from*, since the
     *                          store dies with the request.
     */
    function wp_cache_set(string $key, $value, string $group = '', int $expiration = 0): bool
    {
        WpStub::$objectCache[$group][$key] = $value;
        WpStub::$objectCacheWrites[] = [
            'key' => $key,
            'group' => $group,
            'value' => $value,
            'expiration' => $expiration,
        ];

        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    /**
     * A delete from a group that does not exist is a silent success, as in
     * WordPress — which is why the group name has to be right at every call
     * site and a wrong one fails invisibly.
     */
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        unset(WpStub::$objectCache[$group][$key]);

        return true;
    }
}

if (!function_exists('wp_using_ext_object_cache')) {
    /**
     * Whether a *persistent* object cache backend is installed.
     *
     * Defaults to false in {@see WpStub}, because that is the state of a stock
     * WordPress — and the one a store that assumed persistence would break in.
     * A test flips `WpStub::$usingExtObjectCache` to exercise the other branch.
     */
    function wp_using_ext_object_cache(): bool
    {
        return WpStub::$usingExtObjectCache;
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
        global $admin_page_hooks;

        $admin_page_hooks ??= [];

        WpStub::$menuPages[] = [
            'page_title' => $pageTitle,
            'menu_title' => $menuTitle,
            'capability' => $capability,
            'menu_slug' => $menuSlug,
            'callback' => $callback,
            'icon_url' => $iconUrl,
            'position' => $position,
        ];

        // WordPress records a top-level page's slug here, and
        // `get_plugin_page_hookname()` reads it to decide the hook's page type
        // (`toplevel_page_{slug}` rather than `admin_page_{slug}`).
        $admin_page_hooks[$menuSlug] = 'toplevel';

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

if (!function_exists('get_plugin_page_hookname')) {
    /**
     * Mirrors WordPress's page-type rule for a plugin page's hook name.
     *
     * The real function reads `$admin_page_hooks` (filled by `add_menu_page()`)
     * and `get_admin_page_parent()`. Reproducing both faithfully matters: the
     * hidden-page title fix depends on the hook being `admin_page_{slug}` for a
     * page whose parent is empty, and a stub that guessed the name would let a
     * wrong hook pass the suite - which is how the original bug shipped.
     */
    function get_plugin_page_hookname(string $pluginPage, string $parentPage = ''): string
    {
        global $admin_page_hooks;

        $admin_page_hooks ??= [];

        $pageType = 'admin';

        if ($parentPage === '' || $parentPage === 'admin.php' || isset($admin_page_hooks[$pluginPage])) {
            if (isset($admin_page_hooks[$pluginPage])) {
                $pageType = 'toplevel';
            } elseif (isset($admin_page_hooks[$parentPage])) {
                $pageType = $admin_page_hooks[$parentPage];
            }
        } elseif (isset($admin_page_hooks[$parentPage])) {
            $pageType = $admin_page_hooks[$parentPage];
        }

        $pluginName = preg_replace('!\.php!', '', $pluginPage);

        return $pageType . '_page_' . $pluginName;
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

if (!function_exists('dbDelta')) {
    /**
     * Record a schema statement, and hand it to the fake so a table exists.
     *
     * The real dbDelta() lives in wp-admin/includes/upgrade.php and its job is to
     * *diff* — it parses a CREATE TABLE, compares it against the live table and
     * issues the ALTERs needed to converge. That parsing is deliberately not
     * reproduced here: a second implementation of it would be tested by the very
     * tests it is meant to support, so a passing suite would prove only that the
     * re-implementation agrees with itself.
     *
     * What it *does* do is execute, because the real one does and migrations now
     * depend on that. A migration built on `Schema::create()` does not issue its
     * own CREATE — it hands the statement to dbDelta, which runs it — so a stub
     * that only recorded would leave the table nonexistent and every migration
     * test asserting on a schema that was never built.
     *
     * The execution is a plain `$wpdb->query()`: no diffing, no `ALTER`
     * synthesis. That is the deliberate limit. It is enough for a migration to
     * produce a table a repository can be tested against, and it cannot fake
     * agreement about a schema the adapter got wrong, which is the property the
     * real parser would have hidden.
     *
     * The statement is also recorded, because every rule the WordPress adapter
     * satisfies for dbDelta's benefit is a rule about the statement *text* — the
     * table name unquoted, `PRIMARY KEY` followed by two spaces, each key on its
     * own line — and assertions on that text are assertions on the contract.
     * Those live in {@see \Tests\Unit\Database\WpDatabaseAdapterTest}.
     *
     * ## Which connection it writes to
     *
     * The real dbDelta() reaches for the `$wpdb` global, and so did this stub —
     * which made it a no-op in every test, because the adapter is constructed
     * with an explicit handle and never publishes it to the global. A migration
     * would report success, write its tracking row, and leave no table behind:
     * the one failure mode this stub exists to prevent, arrived at from the other
     * side. So the handle is now taken from the adapter that is mid-call when one
     * is available ({@see WpDatabaseAdapter::connection()}), and the global is
     * only the fallback.
     *
     * @return mixed Whatever the test set as the simulated result.
     */
    function dbDelta($queries = '', $execute = true)
    {
        foreach ((array) $queries as $query) {
            $query = (string) $query;

            WpStub::$dbDeltaStatements[] = $query;

            // A dbDelta() call with no database behind it — which happens in the
            // unit tests that only want the recorded text — still records and
            // returns, rather than fataling on a missing global.
            $db = \Tests\Support\SchemaAdapterRegistry::connection();

            if (!$execute || $db === null) {
                continue;
            }

            // **The one piece of diffing this stub does model, and it is the
            // load-bearing one.** The real dbDelta() checks whether each table
            // exists and does *not* re-issue a CREATE for one that does — it
            // converges the live table with ALTERs instead. Replaying the CREATE
            // here instead would make the fake reject a duplicate and fail every
            // migration on a second run, which is the opposite of what the real
            // helper does and would make a replay test assert the wrong thing.
            if (preg_match('/^\s*CREATE\s+TABLE\s+`?([A-Za-z0-9_$]+)`?/i', $query, $m) === 1
                && $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($m[1]))) !== null
            ) {
                continue;
            }

            $db->query($query);
        }

        return WpStub::$dbDeltaResult;
    }
}
