<?php
declare(strict_types=1);

namespace Tests\Support;

/**
 * Minimal WordPress function surface, defined once per PHP process.
 *
 * The framework's WordPress-facing code is guarded by function_exists() and
 * class_exists() checks, so it can be exercised without a WordPress install —
 * but only if something defines those functions. This class loads them.
 *
 * It is a plain function-defining file rather than a PHPUnit extension because
 * PHP cannot undeclare a function: the stubs must exist for the whole process,
 * and tests that need different behaviour change state through the recorders
 * below rather than by redefining anything.
 *
 * Loaded from tests/bootstrap.php, before the autoloader's own side effects.
 */
final class WpStub
{
    /**
     * Hooks registered through add_action(), as tag => list of entries.
     *
     * Each entry is `['callback' => callable, 'acceptedArgs' => int]` rather than
     * a bare callable, because the accepted-argument count is part of what
     * `add_action()` means and a stub that dropped it could not tell a correctly
     * declared listener from one that would fatal in production.
     *
     * @var array<string, list<array{callback: callable, acceptedArgs: int}>>
     */
    public static array $actions = [];

    /**
     * Hook tags fired through do_action(), in order, including repeats.
     *
     * A list rather than a count so a test can assert on *order* — the thing a
     * migration-events test most wants to check — as well as on presence.
     *
     * @var list<string>
     */
    public static array $fired = [];

    /**
     * Routes handed to register_rest_route(), as namespace => list of args.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    public static array $registeredRoutes = [];

    /**
     * The blanket answer of current_user_can(), for capabilities not listed in
     * {@see $capabilities}.
     */
    public static bool $currentUserCan = true;

    /**
     * Per-capability answers, as capability => bool.
     *
     * Consulted before {@see $currentUserCan}, so a test can hold one capability
     * and lack another — which is the only way to catch two guards that ask for
     * *different* capabilities. A blanket answer cannot: it makes them agree by
     * construction, and the disagreement ships.
     *
     * @var array<string, bool>
     */
    public static array $capabilities = [];

    /**
     * Every capability current_user_can() was asked about, in order.
     *
     * For asserting that a guard asks the capability it is *supposed* to, which
     * is a different question from whether it granted — a check that asks the
     * wrong capability and happens to be granted is the bug this catches.
     *
     * @var list<string>
     */
    public static array $capabilitiesAsked = [];

    public static bool $userLoggedIn = false;

    /**
     * @var array<string, mixed>
     */
    public static array $options = [];

    /**
     * The `$autoload` flag each option was last written with, as option name =>
     * bool.
     *
     * Kept beside {@see $options} rather than folded into it because the two
     * answer different questions: the value is what the option *is*, and this is
     * how it was *stored*. Several options here are deliberately written with
     * autoload off — the migration high-water mark, the uninstall toggle — and
     * that is a decision a test should be able to assert rather than infer.
     *
     * @var array<string, bool>
     */
    public static array $optionAutoload = [];

    /**
     * Transients, as name => value. A transient is an option with an expiry, and
     * nothing the framework does depends on the expiry, so the two stores are
     * kept apart only so a test can tell which one was written.
     *
     * @var array<string, mixed>
     */
    public static array $transients = [];

    /**
     * The user id `get_current_user_id()` reports, and the id
     * `wp_set_current_user()` last set. 0 is "nobody".
     */
    public static int $currentUserId = 0;

    /**
     * Nonces `wp_create_nonce()` has issued, as nonce => action. A nonce the
     * stub did not issue fails verification, which is what a test wants: a
     * hardcoded string in a test would pass against a framework that never
     * verified anything.
     *
     * @var array<string, string>
     */
    public static array $nonces = [];

    /**
     * Every call to `wp_safe_redirect()`, in order.
     *
     * @var list<array{url: string, status: int}>
     */
    public static array $redirects = [];

    /**
     * Every call to `wp_send_json()`, in order.
     *
     * @var list<array{data: mixed, status: int}>
     */
    public static array $jsonResponses = [];

    /**
     * Every call to `set_transient()`, in order, so a test can assert the TTL a
     * flash message was written with rather than only its value.
     *
     * @var list<array{key: string, value: mixed, expiration: int}>
     */
    public static array $transientWrites = [];

    /**
     * Every call to `setcookie()`, captured rather than emitted — a real
     * setcookie() in a test process fails with "headers already sent".
     *
     * @var list<array{name: string, value: string, options: array<string, mixed>}>
     */
    public static array $cookies = [];

    /**
     * Every call to `wp_die()`. The stub throws instead of exiting so the test
     * can see that a die was reached and with what message.
     *
     * @var list<array{message: string, title: string, args: array<string, mixed>}>
     */
    public static array $died = [];

    /**
     * Status codes passed to `status_header()`, in order.
     *
     * @var list<int>
     */
    public static array $statusHeaders = [];

    /**
     * What `wp_login_url()` returns. Null makes the function behave as WordPress
     * does — appending the target as `redirect_to`.
     */
    public static ?string $loginUrl = null;

    /**
     * Top-level admin screens, in the order `add_menu_page()` was called.
     *
     * @var list<array<string, mixed>>
     */
    public static array $menuPages = [];

    /**
     * Submenu screens, in the order `add_submenu_page()` was called.
     *
     * @var list<array<string, mixed>>
     */
    public static array $submenuPages = [];

    /**
     * What `admin_url()` is rooted at.
     */
    public static ?string $adminUrl = null;

    /**
     * What `home_url()` is rooted at.
     */
    public static ?string $homeUrl = null;

    /**
     * What `is_admin()` reports — the flag that decides whether a request is an
     * admin screen or a front-end page.
     */
    public static bool $isAdmin = false;

    // -------------------------------------------------------- plugin lifecycle

    /**
     * Callbacks registered through `register_activation_hook()`, as plugin
     * basename => callable.
     *
     * Keyed by basename because that is how WordPress identifies a plugin, and
     * it is the key that decides whether the hook fires at all: the stub refuses
     * a path with no directory separator, exactly as core's `plugin_basename()`
     * check does. A test asserting on the key therefore also asserts the hook
     * would really be registered.
     *
     * @var array<string, callable>
     */
    public static array $activationHooks = [];

    /**
     * Callbacks registered through `register_deactivation_hook()`, as basename
     * => callable.
     *
     * @var array<string, callable>
     */
    public static array $deactivationHooks = [];

    /**
     * Callbacks registered through `register_uninstall_hook()`, as basename =>
     * callable.
     *
     * Expected to stay empty. The plugin uses `uninstall.php` instead, because
     * this function keeps its registration in the `uninstall_plugins` option —
     * a callback stored in the database being deleted. A test asserts the empty
     * case, which it could not do against an undefined function.
     *
     * @var array<string, callable>
     */
    public static array $uninstallHooks = [];

    /**
     * Fields added through `add_settings_field()`, in order.
     *
     * @var list<array<string, mixed>>
     */
    public static array $settingsFields = [];

    /**
     * Settings registered through `register_setting()`, in order.
     *
     * Expected to stay empty for the uninstall toggle: the Settings API only
     * writes an option that is *present* in the submission, and an unchecked
     * checkbox is not submitted — so registering it would leave a previous
     * `true` in place when the user unchecks the box. See
     * {@see \App\Lifecycle\UninstallSettings}.
     *
     * @var list<array<string, mixed>>
     */
    public static array $registeredSettings = [];

    /**
     * Statements handed to `dbDelta()`, in order.
     *
     * Recorded rather than executed. `dbDelta()` is WordPress's schema *differ*
     * — it reads a CREATE TABLE, compares it against the live table and issues
     * whatever ALTERs it decides are needed — so running it would mean
     * re-implementing its parser here, and a test double for a parser proves
     * only that the double agrees with itself.
     *
     * What a test actually needs to assert is the *statement*, because every
     * dbDelta rule the adapter satisfies is a rule about the text: the table
     * name must be unquoted, `PRIMARY KEY` must be followed by two spaces, each
     * key must be on its own line. Asserting on the text is asserting on the
     * contract; asserting on a simulated diff would not be.
     *
     * @var list<string>
     */
    public static array $dbDeltaStatements = [];

    /**
     * The value `dbDelta()` returns. Defaults to an empty array, which is what
     * the real function returns for a statement it parses and finds nothing to
     * change — the success case, not a failure one.
     *
     * @var mixed
     */
    public static mixed $dbDeltaResult = [];

    public static function reset(): void
    {
        self::$actions = [];
        self::$fired = [];
        self::$registeredRoutes = [];
        self::$currentUserCan = true;
        self::$capabilities = [];
        self::$capabilitiesAsked = [];
        self::$userLoggedIn = false;
        self::$options = [];
        self::$optionAutoload = [];
        self::$transients = [];
        self::$currentUserId = 0;
        self::$nonces = [];
        self::$redirects = [];
        self::$jsonResponses = [];
        self::$transientWrites = [];
        self::$cookies = [];
        self::$died = [];
        self::$statusHeaders = [];
        self::$loginUrl = null;
        self::$menuPages = [];
        self::$submenuPages = [];
        self::$adminUrl = null;
        self::$homeUrl = null;
        self::$isAdmin = false;
        self::$dbDeltaStatements = [];
        self::$dbDeltaResult = [];
        self::$activationHooks = [];
        self::$deactivationHooks = [];
        self::$uninstallHooks = [];
        self::$settingsFields = [];
        self::$registeredSettings = [];
    }

    /**
     * Fire a plugin's activation hook, as WordPress does.
     *
     * The counterpart to {@see doAction()} for the lifecycle hooks, which are
     * not actions: `register_activation_hook()` stores a single callback under
     * the plugin's basename key, and WordPress invokes it once, with no
     * arguments, from `activate_plugin()`.
     */
    public static function activatePlugin(string $basename): void
    {
        $callback = self::$activationHooks[$basename] ?? null;

        if ($callback !== null) {
            $callback();
        }
    }

    /**
     * Fire a plugin's deactivation hook. See {@see activatePlugin()}.
     */
    public static function deactivatePlugin(string $basename): void
    {
        $callback = self::$deactivationHooks[$basename] ?? null;

        if ($callback !== null) {
            $callback();
        }
    }

    /**
     * Issue a nonce the way wp_create_nonce() would, so a test can send a value
     * that genuinely verifies.
     */
    public static function issueNonce(string $action = '-1'): string
    {
        $nonce = substr(md5($action . count(self::$nonces)), 0, 10);
        self::$nonces[$nonce] = $action;

        return $nonce;
    }

    /**
     * Fire every callback registered for a hook, as WordPress would.
     *
     * Honours the registered `acceptedArgs` the way `do_action()` does: a
     * callback declared with one parameter on a hook fired with three receives
     * one. Calling every callback with every argument would let a
     * wrongly-declared listener — the kind that fatals in production — pass here.
     */
    public static function doAction(string $tag, mixed ...$args): void
    {
        foreach (self::$actions[$tag] ?? [] as $entry) {
            $callback = $entry['callback'] ?? $entry;
            $accepted = $entry['acceptedArgs'] ?? count($args);

            $callback(...array_slice($args, 0, $accepted));
        }
    }

    /**
     * Callbacks registered for a hook, as bare callables.
     *
     * @return list<callable>
     */
    public static function actionsFor(string $tag): array
    {
        return array_map(
            static fn (array|callable $entry): callable => is_array($entry) && isset($entry['callback'])
                ? $entry['callback']
                : $entry,
            self::$actions[$tag] ?? []
        );
    }
}
