<?php
declare(strict_types=1);

namespace Framework\Http\Responses;

use Framework\Config\Config;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Exceptions\HttpException;
use Framework\Http\Request;

/**
 * A redirect, with flash-message support.
 *
 * Wraps `wp_safe_redirect()`, which validates the destination against
 * `allowed_redirect_hosts` and falls back to `wp_validate_redirect()`. That is
 * the right default — an unvalidated redirect to a client-supplied URL is an
 * open redirect — so this class does not offer a way to bypass it. A destination
 * that genuinely leaves the site has to be added to `allowed_redirect_hosts`
 * explicitly, which is a deliberate act rather than a parameter.
 *
 * ## Why flash messages live here and not in the session
 *
 * `$_SESSION` does not exist in WordPress. Core has no session layer at all, and
 * starting a PHP session inside a plugin is a well-known source of bugs: it
 * breaks page caching, collides with other plugins doing the same, and needs
 * `session_start()` to run before any output — which is not guaranteed at the
 * point a redirect is created.
 *
 * Transients are the supported mechanism. `set_transient()` backs onto the
 * options table or a persistent object cache, survives the redirect, and is
 * readable by the request that lands. That is exactly the flash-message
 * lifecycle, and it needs no session.
 *
 * ## The key is client-scoped
 *
 * Transients are global to the site, so a fixed key would let one visitor's
 * flash message appear on another visitor's page — or be consumed by them. The
 * key is therefore derived from the user id when logged in, and from a cookie
 * the client carries otherwise. The cookie is set alongside the transient and
 * read back by {@see readFlash()}.
 */
final class RedirectResponse implements ResponseInterface
{
    /**
     * Cookie carrying the client's flash key.
     *
     * A constant rather than config: two plugins each wanting their own key is a
     * reason to change the *value*, not the name — and the name must be stable
     * for {@see readFlash()} to find a key written by an earlier request.
     */
    public const FLASH_COOKIE = 'wp_mini_laravel_flash';

    /**
     * How long a flash message survives if the next request never arrives, in
     * seconds. Short enough not to accumulate, long enough to survive a login
     * round trip or a slow page load.
     */
    public const FLASH_TTL = 300;

    /**
     * The option prefix used when configuration has not loaded, or has no value.
     *
     * ## Why this is public, and why it is not just a default argument
     *
     * The flash transient key is `{prefix}flash_{scope}`, and the prefix comes
     * from `config('app.prefix')`. When the config layer is absent — PHPUnit, a
     * WP-CLI command, any code running before the entry file boots — that call
     * returns null and this fallback is what the key is actually built from.
     *
     * That made the fallback load-bearing rather than decorative, and it had to
     * be published. A test double that reconstructs the key is a second
     * implementation of this convention, and the two agree only as long as they
     * name the same literal. They did — but only because both spelled out the
     * library's own name by hand, so neither moved when the project's prefix
     * did. The suite passed while storing under one key and reading from
     * another, because the project's real prefix is not this one.
     *
     * Exposing it gives the double something to read instead of something to
     * copy: {@see \Tests\Support\FlashStore} now derives the key the same way
     * this class does, and a change to the fallback reaches both.
     */
    public const DEFAULT_PREFIX = Config::DEFAULT_PREFIX;

    /**
     * The status codes `wp_redirect()` accepts. Anything else is a hard stop.
     *
     * WordPress's list, not HTTP's: `wp_redirect()` rejects everything outside
     * 300-399, so 304 is technically permitted by the range check while this
     * class does not offer it — a 304 is not a redirect a caller means to send.
     *
     * @var list<int>
     */
    private const STATUSES = [301, 302, 303, 307, 308];

    private bool $sent = false;

    /**
     * Declared rather than promoted, because the constructor validates it before
     * it is assigned — a promoted `readonly` parameter must be initialised
     * before the body runs, which would leave the guard unreachable.
     */
    private readonly int $status;

    /** @var array<string, mixed> */
    private array $flash = [];

    /**
     * @param Request|null $request The request being answered, used only to decide
     *                              the flash cookie's `Secure` flag. Optional, but
     *                              passing it is what makes the flag correct behind
     *                              a TLS-terminating proxy, where `$_SERVER['HTTPS']`
     *                              is unset and `Request::isSecure()` consults
     *                              `X-Forwarded-Proto` instead.
     *
     * @throws HttpException When the status is not a redirect status. Thrown
     *                       here, at construction, rather than at `send()`:
     *                       `wp_redirect()` responds to a bad status by calling
     *                       `wp_die()`, so by the time `send()` runs the request
     *                       is already over and the mistake is unrecoverable.
     *                       Failing where the value was supplied points at the
     *                       line that got it wrong.
     */
    public function __construct(
        private string $url,
        int $status = 302,
        private readonly ?Request $request = null
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new HttpException(sprintf(
                'A redirect status must be one of %s; %d was given. WordPress calls wp_die() '
                . 'on any other value, which would end the request with an error page instead '
                . 'of redirecting.',
                implode(', ', self::STATUSES),
                $status
            ));
        }

        $this->status = $status;
    }

    /**
     * @throws HttpException When the redirect cannot be performed, or was already
     *                       sent.
     */
    public function send(): void
    {
        if ($this->sent) {
            throw HttpException::alreadySent(self::class);
        }

        if (!function_exists('wp_safe_redirect')) {
            throw new HttpException(
                'wp_safe_redirect() is not available; a RedirectResponse can only be sent from within WordPress.'
            );
        }

        $this->sent = true;

        $this->writeFlash();

        // The status is known-redirect-safe because the constructor rejected
        // anything else. It cannot be left to WordPress: wp_redirect() answers a
        // non-3xx status with wp_die(), not with a fallback to 302.
        wp_safe_redirect($this->url, $this->status);

        // wp_safe_redirect() only emits the header; it does not stop execution,
        // so without this the handler that issued the redirect would carry on
        // and write a body behind it. `exit` is what makes the redirect final.
        exit;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * Point this redirect at a named route, instead of at a URL.
     *
     * What makes `redirect()->route('leads.index')` read the way it does in
     * Laravel. The name is resolved through {@see route()}, so an unknown name
     * fails here with the same message it fails with in a template — one lookup
     * path, one ambiguity check, one exception.
     *
     * Resolution happens *now* rather than at `send()`. A route table is built
     * while the request is handled, and a name that missed would otherwise be
     * discovered after the flash cookie had been written and the request was
     * already over.
     *
     * Returns `$this` rather than a new response so that a redirect carrying a
     * flash message stays one object: `redirect()->route(...)->with(...)` writes
     * the flash onto the response that is going to be sent.
     *
     * @param array<string, scalar> $parameters Placeholder values, keyed by name.
     *
     * @throws \InvalidArgumentException When no route carries the name.
     * @throws \RuntimeException         When a path placeholder has no value.
     */
    public function route(string $name, array $parameters = []): self
    {
        $this->url = route($name, $parameters);

        return $this;
    }

    /**
     * Queue a flash value to be read by the next request.
     *
     * @param mixed $value
     */
    public function with(string $key, mixed $value): self
    {
        $this->flash[$key] = $value;

        return $this;
    }

    /**
     * Queue several flash values at once.
     *
     * @param array<string, mixed> $values
     */
    public function withMessages(array $values): self
    {
        foreach ($values as $key => $value) {
            $this->with((string) $key, $value);
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function flash(): array
    {
        return $this->flash;
    }

    /**
     * Read the flash values left by a previous request, consuming them.
     *
     * Call this once, early in the request that lands after a redirect — a
     * controller or a `template_redirect` handler — and hand the result to the
     * view. Reading is destructive by design: a flash message that could be read
     * twice would reappear on a refresh, which is the bug the whole mechanism
     * exists to avoid.
     *
     * @return array<string, mixed>
     */
    public static function readFlash(): array
    {
        if (!function_exists('get_transient') || !function_exists('delete_transient')) {
            return [];
        }

        $key = self::flashKey(false);

        if ($key === null) {
            return [];
        }

        $flash = get_transient($key);

        if (!is_array($flash)) {
            return [];
        }

        delete_transient($key);

        return $flash;
    }

    /**
     * The option prefix the flash key is built from.
     *
     * Public so that anything reconstructing a flash key — a test double, a
     * debugging tool, an admin screen listing pending messages — derives it the
     * same way rather than re-implementing the rule. Two implementations of a
     * key convention are two things that can disagree, and the disagreement is
     * invisible: the message is written under one key, read from another, and
     * the symptom is a missing notice rather than an error.
     *
     * A `config()` that returns null is treated as absent, not as an empty
     * string. `config('app.prefix', $default)` only returns the default when the
     * key is missing entirely; a key present and set to `''` — which is what an
     * unloaded config layer leaves behind — falls through as an empty string and
     * would build the key `flash_7`, shared by every site using this library.
     */
    public static function resolvePrefix(): string
    {
        if (!function_exists('config')) {
            return self::DEFAULT_PREFIX;
        }

        $prefix = config('app.prefix', self::DEFAULT_PREFIX);

        return is_string($prefix) && $prefix !== '' ? $prefix : self::DEFAULT_PREFIX;
    }

    /**
     * The transient key this request reads and writes.
     *
     * Derived per client, because a transient is site-global: a shared key would
     * hand one visitor's "Your form was rejected" to the next visitor to load a
     * page. A logged-in user is identified by id; an anonymous one by a cookie
     * issued on the redirect. When neither exists the message is dropped rather
     * than written to a shared key — losing a flash message is a cosmetic
     * failure, showing it to the wrong person is a leak.
     *
     * Static because {@see readFlash()} has no instance either — it runs at the
     * start of the *next* request, before any response exists. That is also why
     * `$secure` is a parameter: the only caller that issues a cookie is
     * `writeFlash()`, which knows whether the request was made over HTTPS, and a
     * static method has no `$this` to ask.
     */
    private static function flashKey(bool $create, bool $secure = false): ?string
    {
        $prefix = self::resolvePrefix();

        if (function_exists('get_current_user_id')) {
            $userId = (int) get_current_user_id();

            if ($userId > 0) {
                return $prefix . 'flash_' . $userId;
            }
        }

        $token = isset($_COOKIE[self::FLASH_COOKIE]) && is_string($_COOKIE[self::FLASH_COOKIE])
            ? $_COOKIE[self::FLASH_COOKIE]
            : null;

        // A token from the client is not trusted as an identifier on its own —
        // it is only used to look up a key this site wrote. Constrained to the
        // shape this class issues so a crafted cookie cannot address an
        // arbitrary transient or inject into the key.
        if ($token !== null && preg_match('/^[A-Za-z0-9]{32}$/', $token) === 1) {
            return $prefix . 'flash_' . $token;
        }

        if (!$create) {
            return null;
        }

        $new = bin2hex(random_bytes(16));

        // Not `httponly => true`: the value is a random key, not a credential,
        // and leaving it script-readable costs nothing. `secure` follows the
        // request so the cookie is not dropped on an HTTPS site, and vice versa.
        setcookie(self::FLASH_COOKIE, $new, [
            'expires' => time() + self::FLASH_TTL,
            'path' => '/',
            'secure' => $secure,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);

        return $prefix . 'flash_' . $new;
    }

    /**
     * Whether the connection the client is on is HTTPS.
     *
     * Delegates to the Request when one was supplied, which is the only version
     * that sees `X-Forwarded-Proto` and therefore the only correct one behind a
     * proxy. The `$_SERVER` fallback is for a redirect constructed without a
     * Request, where the header is unreachable — it matches `Request::isSecure()`'s
     * first branch, minus the forwarded check it cannot perform.
     */
    private function isSecure(): bool
    {
        if ($this->request !== null) {
            return $this->request->isSecure();
        }

        $https = $_SERVER['HTTPS'] ?? null;

        return is_string($https) && $https !== '' && strtolower($https) !== 'off';
    }

    private function writeFlash(): void
    {
        if ($this->flash === []) {
            return;
        }

        if (!function_exists('set_transient')) {
            return;
        }

        $key = self::flashKey(true, $this->isSecure());

        if ($key === null) {
            return;
        }

        set_transient($key, $this->flash, self::FLASH_TTL);
    }
}
