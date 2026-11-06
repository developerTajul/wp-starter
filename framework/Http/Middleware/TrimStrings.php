<?php
declare(strict_types=1);

namespace Framework\Http\Middleware;

/**
 * Strips leading and trailing whitespace from every string input.
 *
 * Laravel's middleware of the same name, for the same reason: a value that
 * arrives as `"  ada@example.test "` — a copy-paste with a trailing space, a
 * mobile keyboard's autocomplete adding one — is not what the user meant, and
 * every consumer downstream has to remember to trim it. Doing it once, at the
 * edge, is what lets a validation rule, a comparison and a database write agree
 * without each one carrying its own `trim()`.
 *
 * ## What counts as whitespace
 *
 * PHP's `trim()` default character list: space, tab, newline, carriage return,
 * NUL, and the vertical tab. Note what that does **not** include — a non-breaking
 * space (`\u{00A0}`), which is what a paste from a web page or a Word document
 * often carries. It is deliberately left alone: stripping it would mean handling
 * multibyte input here, which is a different job with its own failure modes, and
 * `SanitizeInput` is where WordPress's own text handling — which does know about
 * it — belongs.
 *
 * ## The interior is untouched
 *
 * `"Ada  Lovelace"` keeps its double space. Collapsing interior runs is a
 * *sanitization* decision rather than a trimming one, and it changes a value the
 * user typed deliberately — a pasted address, a code, a signature block. Trim
 * asks "did the edges get noise?", not "is this text tidy?".
 */
final class TrimStrings extends TransformsInput
{
    protected function applyToString(string $value): string
    {
        return trim($value);
    }
}
