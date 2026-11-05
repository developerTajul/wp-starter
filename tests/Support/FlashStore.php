<?php
declare(strict_types=1);

namespace Tests\Support;

use Framework\Http\Responses\RedirectResponse;

/**
 * Puts a flash payload where {@see ViewRenderer} will find it.
 *
 * ## Why this exists rather than each test inlining it
 *
 * The flash transient key is derived by `RedirectResponse::flashKey()`, which is
 * private and depends on whether a user is logged in and on a cookie the client
 * carries. Reproducing that derivation in every test would mean two places
 * knowing a private convention, and a change to the key shape would silently
 * turn those tests into ones that assert against an *empty* flash — they would
 * keep passing while testing nothing.
 *
 * So the derivation lives in exactly one test-only place, written by reading the
 * same inputs the class reads: no user id, and a token matching the shape the
 * class issues (32 hex characters, per the pattern in `flashKey()`). If that
 * pattern changes, this breaks loudly — which is the correct failure for a test
 * double that has drifted from what it doubles.
 */
final class FlashStore
{
    /**
     * Write a flash payload, and assert it is findable.
     *
     * **The argument is the whole flash map, not one of its values.** The keys
     * the renderer reads are `errors`, `old`, `error` and `notice`, so a payload
     * is `['old' => [...], 'errors' => [...]]` — not `['email' => '...']`, which
     * stores nothing the renderer looks for and produces an empty flash that
     * looks exactly like a broken feature. The assertion below catches the
     * mis-shape that is detectable; the naming is the guard against the rest.
     *
     * The verification is the point. A test that writes to a key the renderer
     * does not read gets an empty flash, which is indistinguishable from "the
     * feature is broken" — and the assertion here says which of the two it is.
     *
     * @param array<string, mixed> $flash Keys the renderer reads: errors, old, error, notice.
     */
    public static function write(array $flash): void
    {
        $unknown = array_diff(array_keys($flash), ['errors', 'old', 'error', 'notice']);

        if ($unknown !== []) {
            throw new \RuntimeException(sprintf(
                'Flash keys [%s] are not read by the renderer. Expected the whole flash map — '
                . 'e.g. [\'old\' => [...]] — not one of its inner values.',
                implode(', ', $unknown)
            ));
        }

        WpStub::$transients[self::key()] = $flash;
    }

    /**
     * Whether the payload has been consumed since {@see write()}.
     *
     * The renderer does not expose this, and "was the transient destroyed" is a
     * different question from "did the renderer see the value" — the second can
     * be true of a renderer that leaked the first, which is the bug worth
     * catching.
     */
    public static function consumed(): bool
    {
        return !array_key_exists(self::key(), WpStub::$transients);
    }

    /**
     * The transient key an anonymous visitor's flash is written under.
     *
     * Mirrors `RedirectResponse::flashKey(false)` for a request with no logged-in
     * user: the prefix, `flash_`, and the cookie token.
     *
     * ## Why the prefix is read from the class rather than from config
     *
     * The prefix is `config('app.prefix')`, and under PHPUnit that call is empty
     * — the config layer is loaded by the entry file, which a unit test does not
     * run. So the key the class actually builds is its *fallback*, and a double
     * that asks config gets nothing back and has to supply a fallback of its own.
     *
     * It used to: this method hardcoded `'wp_mini_laravel_'`. That agreed with
     * the class only because both had independently written down the library's
     * own name. On the unbranded project the two matched and the suite was green;
     * on a rebranded one, where the project's prefix is `lead_bridge_`, the
     * double wrote under `wp_mini_laravel_flash_…` and the class read from
     * `lead_bridge_flash_…` — and every flash test failed with the message
     * replaced by an empty string, which reads as a broken renderer rather than
     * as two halves of a test disagreeing about a key.
     *
     * `resolvePrefix()` is the class's own answer to this question, so asking it
     * makes that class of drift impossible rather than merely fixed.
     */
    private static function key(): string
    {
        $token = $_COOKIE[RedirectResponse::FLASH_COOKIE] ?? null;

        // The class only accepts a token of this shape — a client-supplied cookie
        // is not trusted as an identifier, only used to look up a key this site
        // wrote — so a test double has to satisfy the same pattern or it is
        // testing a code path production cannot reach.
        if (!is_string($token) || preg_match('/^[A-Za-z0-9]{32}$/', $token) !== 1) {
            $token = bin2hex(random_bytes(16));
            $_COOKIE[RedirectResponse::FLASH_COOKIE] = $token;
        }

        return RedirectResponse::resolvePrefix() . 'flash_' . $token;
    }
}
