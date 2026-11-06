<?php
declare(strict_types=1);

namespace Framework\Http\Middleware;

/**
 * Turns empty string input into null.
 *
 * Laravel's middleware of the same name. An HTML form cannot express "no value":
 * a text input the user left blank is submitted as `email=`, so an optional field
 * arrives as `''` rather than as absent. That is indistinguishable from a field
 * that was submitted empty on purpose, and it is the wrong answer for every
 * consumer that asks "did the client send this?" — an `isset()` check, a
 * `??` fallback, a column that should hold NULL rather than the empty string.
 *
 * Normalising to null at the edge is what makes "not sent", "sent empty" and
 * "sent null" collapse into one case, which is the reading a form actually
 * intends.
 *
 * ## After TrimStrings, not before
 *
 * A field of `"   "` is still `''` after this middleware only if it was trimmed
 * first — so `TrimStrings` must run **before** this one, which is the order
 * `routes/web.php` declares. Declared the other way round, a whitespace-only
 * field stays `'   '` and does not become null, which is a subtle difference that
 * shows up as an optional field being "filled in" with spaces.
 *
 * ## What is not touched
 *
 * Only exact empty strings. `'0'` is a legitimate value — a quantity, a price, a
 * selection — and is left alone; PHP's falsy coercions would happily eat it,
 * which is the classic bug this middleware's one-line body avoids. Nested arrays
 * are walked, so `lead[phone]=''` becomes null at the leaf, not an empty array.
 */
final class ConvertEmptyStringsToNull extends TransformsInput
{
    /**
     * @return string|null
     */
    protected function applyToString(string $value): mixed
    {
        return $value === '' ? null : $value;
    }
}
