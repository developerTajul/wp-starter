<?php
declare(strict_types=1);

namespace Framework\Routing;

/**
 * Turns declared routes into the shape WordPress matches against.
 *
 * Two jobs, both of which every routing domain needs and none of which should
 * exist twice:
 *
 *  - **Translation.** `posts/{id:\d+}` becomes the regex WordPress compiles.
 *    This is a real parser, not a string replace: a constraint is itself a regex
 *    and can contain braces and nested parens, so where a placeholder ends is a
 *    question only a scanner can answer.
 *  - **Ordering.** Most-specific-first, because WordPress takes the first pattern
 *    that matches and `posts/{id}` registered before `posts/latest` would
 *    dispatch `show` with `id="latest"`.
 *
 * ## Why this is one class and not a method on each router
 *
 * The REST router and the web adapter must agree on what `{id}` means. Two
 * implementations of the same grammar drift — and they drift *silently*, because
 * each one still works for the cases its own tests cover while the two disagree
 * on the awkward ones. The symptom is a route that 404s in one domain and
 * resolves in the other, with nothing raised at registration. So the parser has
 * exactly one home, and {@see Router} delegates to it rather than keeping a
 * second copy.
 *
 * Stateless, and every method is static: this is a pure function over a path.
 */
final class RouteCompiler
{
    /**
     * Translate a route path into the regex WordPress matches request paths
     * against.
     *
     * WordPress hands the registered `route` the path with the namespace already
     * stripped, and compiles it as `@^<route>$@i`, so everything that is not a
     * placeholder must be escaped. Without that, a literal `.` matches any
     * character (`file.json` and `fileXjson` both dispatch), and a stray `(`,
     * `[` or `+` corrupts the pattern so `preg_match` fails and the route is
     * silently dead.
     *
     * Placeholder constraints are left as written — they are regex by design.
     */
    public static function toPattern(string $path): string
    {
        return self::escapeNonPlaceholders(self::substitutePlaceholders($path));
    }

    /**
     * Order routes most-specific-first.
     *
     * WordPress tries every registered pattern in insertion order and takes the
     * first that matches, so `posts/{id}` registered before `posts/latest` would
     * dispatch `show` with id="latest" — a silent wrong-action dispatch, not a
     * 404. Counting literal segments rather than comparing patterns keeps this
     * predictable: the more of a path an author spelled out, the earlier it is
     * matched.
     *
     * The sort is stable in effect for equally-specific routes because usort is
     * given a total order here: the second key breaks every tie the first leaves,
     * and two routes identical on both keys keep their relative order only by
     * accident of the sort — which is why the duplicate guard in the adapters
     * refuses a same-method-same-path pair rather than relying on this.
     *
     * @param array<int|string, Route> $routes
     * @return list<Route>
     */
    public static function sortBySpecificity(array $routes): array
    {
        $routes = array_values($routes);

        usort($routes, static function (Route $a, Route $b): int {
            $specificity = self::literalSegments($b->getPath()) <=> self::literalSegments($a->getPath());

            if ($specificity !== 0) {
                return $specificity;
            }

            // Same literal count: prefer the route with fewer wildcards in its
            // placeholders, so a constrained `{id:\d+}` beats a bare `{id}`.
            return self::constrainedSegments($b->getPath()) <=> self::constrainedSegments($a->getPath());
        });

        return $routes;
    }

    /**
     * The literal segments in a path — the ones an author spelled out.
     *
     * A segment containing a placeholder does not count, because it matches more
     * than the one thing it says. `/posts/latest` scores 2, `/posts/{id}` scores
     * 1, which is what puts the literal route first.
     */
    private static function literalSegments(string $path): int
    {
        $count = 0;

        foreach (explode('/', $path) as $segment) {
            if ($segment !== '' && preg_match('/\{[A-Za-z_]/', $segment) !== 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * How many placeholders in a path carry a constraint.
     *
     * Used only as a tie-break when two paths have the same number of literal
     * segments, so a constrained `{id:\d+}` sorts ahead of a bare `{id}`.
     *
     * The scan reuses `findPlaceholderEnd()`, which is the only parser that
     * agrees with `substitutePlaceholders()` about where a placeholder ends. It
     * is not that a `[^}]+` match gets the common cases wrong — on
     * `a/{x:[0-9]{1,3}}/b/{y}` it happens to return the same answer, because it
     * can only ever match the inner `{1,3}` fragment and so counts that one
     * placeholder once. The two diverge on the *empty* constraint: `{x:}` is a
     * placeholder with no constraint under the scanner, and a `[^}]+` match
     * finds nothing at all there, scoring the path as unconstrained.
     */
    private static function constrainedSegments(string $path): int
    {
        $count = 0;
        $length = strlen($path);
        $i = 0;

        while ($i < $length) {
            if ($path[$i] !== '{') {
                $i++;
                continue;
            }

            $end = self::findPlaceholderEnd($path, $i);

            if ($end === null) {
                $i++;
                continue;
            }

            if (str_contains(substr($path, $i + 1, $end - $i - 1), ':')) {
                $count++;
            }

            $i = $end + 1;
        }

        return $count;
    }

    /**
     * Escape everything outside the named groups, so only the literal parts of
     * the path are quoted and a constraint's own regex survives untouched.
     *
     * Scanned with `findPlaceholderEnd()` rather than split on a regex, because
     * this is the same "where does a placeholder end" question
     * `substitutePlaceholders()` answers, and a second, weaker parser here
     * silently produces dead routes: a constraint containing a nested group —
     * `{id:((\d+))}`, `{id:(?:a(b))}`, `{a:[^}]+}` — is a perfectly ordinary
     * regex, but a `\([^()]*\)` splitter cannot span its parens, so it fails to
     * recognise the group at all and `preg_quote`s the whole `(?P<id>...)` into
     * literal text. WordPress then 404s every request to the route, with nothing
     * raised at registration.
     *
     * The group is found by matching the `(?P<name>` opener and then counting
     * parens — including ones inside a character class, which PHP's own compiler
     * treats as literal — to the paren that closes the group. If the group never
     * closes, the remainder is quoted as literal text, which is what an
     * unescaped `(` deserves.
     */
    private static function escapeNonPlaceholders(string $translated): string
    {
        $pattern = '';
        $length = strlen($translated);
        $i = 0;
        $literalStart = 0;

        while ($i < $length) {
            if ($translated[$i] !== '('
                || preg_match('/\G\(\?P<[A-Za-z_][A-Za-z0-9_]*>/', $translated, $m, 0, $i) !== 1
            ) {
                $i++;
                continue;
            }

            $end = self::findGroupEnd($translated, $i + strlen($m[0]));

            if ($end === null) {
                $i++;
                continue;
            }

            $pattern .= preg_quote(substr($translated, $literalStart, $i - $literalStart), '@');
            $pattern .= substr($translated, $i, $end - $i + 1);
            $i = $end + 1;
            $literalStart = $i;
        }

        return $pattern . preg_quote(substr($translated, $literalStart), '@');
    }

    /**
     * The index of the `)` closing the capture group whose body starts at
     * $start, or null when it never closes.
     *
     * Parens inside a character class are literal to PCRE — `[(]` matches a
     * paren, it does not open a group — so a class is skipped whole rather than
     * counted. An escaped paren is skipped too. Bailing out on an unterminated
     * class or a trailing backslash leaves the caller to treat the text as
     * literal, which is the safe direction: quoting a stray `(` produces a
     * pattern that matches a literal paren, where counting it as a group would
     * unbalance the compiled pattern and kill the route.
     */
    private static function findGroupEnd(string $subject, int $start): ?int
    {
        $length = strlen($subject);
        $depth = 1;
        $inClass = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $subject[$i];

            if ($char === '\\') {
                $i++;
                continue;
            }

            if ($inClass) {
                if ($char === ']') {
                    $inClass = false;
                }

                continue;
            }

            if ($char === '[') {
                $inClass = true;
                continue;
            }

            if ($char === '(') {
                $depth++;
                continue;
            }

            if ($char !== ')') {
                continue;
            }

            $depth--;

            if ($depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Replace `{name}` and `{name:constraint}` with a named capture group.
     *
     * Scanned rather than matched with a regex, because a constraint is itself a
     * regex and can contain braces — `{id:[0-9]{1,3}}` is a natural way to bound a
     * numeric id, and a `[^}]+` character class stops at the quantifier's closing
     * brace. That truncation is silent: the compiled pattern then contains
     * `[0-9]{1,3)\}`, which matches nothing, so the route is dead and WordPress
     * 404s every request to it with no error at registration.
     *
     * Braces are therefore counted: the constraint ends at the `}` that returns
     * the depth to zero, so a quantifier or a nested group inside it is kept whole.
     */
    private static function substitutePlaceholders(string $path): string
    {
        $result = '';
        $length = strlen($path);
        $i = 0;

        while ($i < $length) {
            if ($path[$i] !== '{') {
                $result .= $path[$i];
                $i++;
                continue;
            }

            $end = self::findPlaceholderEnd($path, $i);

            // Not a placeholder — an unmatched `{`, or a name that does not fit
            // the convention. Left verbatim so the caller's escaping handles it,
            // rather than swallowed as a malformed group.
            if ($end === null) {
                $result .= $path[$i];
                $i++;
                continue;
            }

            $body = substr($path, $i + 1, $end - $i - 1);
            [$name, $constraint] = array_pad(explode(':', $body, 2), 2, null);

            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                $result .= substr($path, $i, $end - $i + 1);
                $i = $end + 1;
                continue;
            }

            // `{id:}` splits to an empty constraint, which is *not* null — so a
            // `?? '[^/]+'` would not catch it, and the group would compile to
            // `(?P<id>)`. That matches the empty string rather than a path
            // segment, and since WordPress anchors every pattern, such a route
            // would also shadow any sibling it is registered before. Treated as
            // "no constraint given" instead.
            if ($constraint === null || trim($constraint) === '') {
                $constraint = '[^/]+';
            }

            $result .= sprintf('(?P<%s>%s)', $name, $constraint);
            $i = $end + 1;
        }

        return $result;
    }

    /**
     * The index of the `}` closing the placeholder that opens at $start, or null
     * when there is no such brace (or the body is not a plausible placeholder).
     *
     * A `:` splits the name from the constraint, and everything after it is
     * treated as regex — where a `{` opens a quantifier that must be closed before
     * the placeholder itself can close. Before the `:` a brace cannot legitimately
     * appear, so one there means this was never a placeholder.
     */
    private static function findPlaceholderEnd(string $path, int $start): ?int
    {
        $length = strlen($path);
        $depth = 0;
        $seenColon = false;

        for ($i = $start + 1; $i < $length; $i++) {
            $char = $path[$i];

            if ($char === ':') {
                $seenColon = true;
                continue;
            }

            if ($char === '{') {
                if (!$seenColon) {
                    return null;
                }

                $depth++;
                continue;
            }

            if ($char !== '}') {
                continue;
            }

            if ($depth > 0) {
                $depth--;
                continue;
            }

            return $i;
        }

        return null;
    }
}
