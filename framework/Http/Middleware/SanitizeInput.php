<?php
declare(strict_types=1);

namespace Framework\Http\Middleware;

/**
 * Sanitizes every string input as a baseline safety net.
 *
 * What this is, and what it is not
 *
 * This is **defence in depth, not a substitute for escaping at the point of
 * output**. `sanitize_text_field()` strips tags, removes line breaks and extra
 * whitespace, and drops invalid UTF-8 - it makes a value safe to *store* and to
 * handle, and it is the right baseline for input that will be displayed. It does
 * **not** make a value safe to print: HTML in an attribute, a URL, a JavaScript
 * context and a database column each need their own treatment, and a view still
 * escapes with {@see e()} (or `esc_html()`/`esc_attr()`) regardless of what ran
 * here. A project that treats this middleware as the answer to output escaping
 * has moved the bug, not fixed it.
 *
 * Why it strips newlines, and why that matters for textareas
 *
 * `sanitize_text_field()`'s line-break handling is the one behaviour worth
 * knowing about before enabling this globally: a multi-line textarea - a notes
 * field, an address, a message body - comes back as a single line. That is
 * WordPress's decision for *single-line* text input, and it is correct for
 * titles and names.
 *
 * **A route with a genuine multi-line field should not run this middleware**, or
 * should run `wp_kses_post()` instead when the field is meant to hold limited
 * HTML. Enabling it as a blanket default on every web route - which is a fair
 * reading of "baseline security" - is exactly how a notes field silently loses
 * its line breaks. The routes file is where that choice belongs, which is why
 * this is a middleware a route opts into rather than something the pipeline does
 * for everyone.
 *
 * `wp_unslash()` runs first
 *
 * WordPress adds slashes to superglobal input at bootstrap
 * (`wp_magic_quotes()`), so `$_POST['name']` for a typed `O'Brien` is really
 * `O\'Brien`. Sanitizing without unslashing first strips the backslash -
 * `sanitize_text_field()` removes it - and leaves `O'Brien`... which is correct
 * here by accident and wrong the moment the value is not an apostrophe:
 * `a\"b` loses its quote entirely.
 *
 * The order is unslash, *then* sanitize, which is the order
 * `sanitize_text_field( wp_unslash( $value ) )` - WordPress's own documented
 * idiom - applies. {@see Request} does not unslash, deliberately: it is the
 * honest transport layer, and guessing at WordPress's slashing would corrupt a
 * genuine backslash in a JSON body, where `wp_magic_quotes()` never reached. So
 * the unslashing belongs here, at the point where WordPress's convention is
 * known to apply.
 *
 * No WordPress, no sanitizing
 *
 * Outside WordPress - a test, a CLI context - `sanitize_text_field()` does not
 * exist, and there is no framework-native equivalent that means the same thing
 * (PHP's `filter_var()` and `strip_tags()` are different washes). Rather than
 * substitute a near-miss that would behave differently in production,
 * `wp_unslash()`-and-sanitize is skipped and the value passes through unchanged.
 *
 * This is the opposite of the fail-closed choice {@see VerifyAdminNonce} and
 * {@see CheckCapability} make, and the difference is deliberate: those are
 * *authorization* checks, where failing open is a vulnerability. This is a
 * hygiene pass, where the alternative to running is not "allow the request" but
 * "do nothing extra to it" - and a test asserting what a view prints should see
 * the value it actually put in, not a WordPress-shaped approximation of it.
 */
final class SanitizeInput extends TransformsInput
{
    protected function applyToString(string $value): string
    {
        if (!function_exists('sanitize_text_field')) {
            return $value;
        }

        // Unslashed first, then sanitized - see the class docblock for why the
        // order is not interchangeable.
        $value = function_exists('wp_unslash') ? wp_unslash($value) : $value;

        // `wp_unslash()` is typed `mixed` and returns its argument unchanged for
        // a non-string. It cannot receive one from `apply()`, which only calls
        // this for strings, but the narrowing is stated rather than assumed so a
        // future change to `wp_unslash()` cannot quietly put a non-string into
        // this string-typed path.
        if (!is_string($value)) {
            return '';
        }

        return sanitize_text_field($value);
    }
}
