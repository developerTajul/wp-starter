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
     * Hooks registered through add_action(), as tag => list of callbacks.
     *
     * @var array<string, list<callable>>
     */
    public static array $actions = [];

    /**
     * Routes handed to register_rest_route(), as namespace => list of args.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    public static array $registeredRoutes = [];

    /**
     * The response of the next current_user_can() call, or the default.
     */
    public static bool $currentUserCan = true;

    public static bool $userLoggedIn = false;

    /**
     * @var array<string, mixed>
     */
    public static array $options = [];

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

    public static function reset(): void
    {
        self::$actions = [];
        self::$registeredRoutes = [];
        self::$currentUserCan = true;
        self::$userLoggedIn = false;
        self::$options = [];
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
     */
    public static function doAction(string $tag, mixed ...$args): void
    {
        foreach (self::$actions[$tag] ?? [] as $callback) {
            $callback(...$args);
        }
    }

    /**
     * Callbacks registered for a hook.
     *
     * @return list<callable>
     */
    public static function actionsFor(string $tag): array
    {
        return self::$actions[$tag] ?? [];
    }
}
