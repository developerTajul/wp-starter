<?php
declare(strict_types=1);

namespace Framework\Routing;

/**
 * Turns declared routes into the shape WordPress matches against.
 *
 * Three jobs, all of which every routing domain needs and none of which should
 * exist twice:
 *
 *  - **Translation.** `posts/{id:\d+}` becomes the regex WordPress compiles.
 *    This is a real parser, not a string replace: a constraint is itself a regex
 *    and can contain braces and nested parens, so where a placeholder ends is a
 *    question only a scanner can answer.
 *  - **Extraction.** The placeholder names in a path, in order. Answered by the
 *    same scanner, for the same reason.
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
 * **This class is that home, and every other module delegates to it.** Three
 * callers used to carry their own regex for the same grammar —
 * `Route::getParameterNames()`, `UrlGenerator::substitute()` and
 * `WordPressRouterAdapter::actionName()` — and they disagreed. On `posts/{id:}`
 * (a placeholder with an empty constraint, which this compiler reads as "no
 * constraint" and compiles to a working `(?P<id>[^/]+)`), the two
 * `\{...(?::[^}]+)?\}` regexes matched *nothing at all*: the route dispatched
 * correctly while `getParameterNames()` reported no parameters, so parameter
 * binding silently dropped the argument and `UrlGenerator` rejected a value it
 * had been given as unused. The adapter's `\{[^}]*\}` was wrong differently and
 * more quietly — it mangled a compiled `(?P<id>…)` path into an action name
 * containing `p_id`, and got the quantifier case right only because the stray
 * brace it left behind happened to normalise to a separator. A green suite did
 * not catch any of it, because nothing compared the answers.
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
     * The placeholder names in a path, in the order they appear.
     *
     * The counterpart to {@see toPattern()}: the names it compiles are the names
     * this returns, because both walk the path with {@see findPlaceholderEnd()}.
     * That is the point of the method existing here rather than at each call site
     * — a caller that extracted names with its own regex would be asserting a
     * reading of the grammar that the compiler does not share.
     *
     * Names are returned once per occurrence, so `posts/{id}/comments/{id}` yields
     * `['id', 'id']`. Duplicates are the caller's to judge: `Router`'s binding
     * step feeds each to a positional argument, where a repeat is legitimate,
     * while `UrlGenerator` only ever asks which names are *known*.
     *
     * An empty list means the path has no placeholders — including the case where
     * it has something that merely looks like one, such as a `{` that never
     * closes or a body that is not a name.
     *
     * @return list<string>
     */
    public static function parameterNames(string $path): array
    {
        $names = [];
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

            $name = self::placeholderName($path, $i, $end);

            if ($name !== null) {
                $names[] = $name;
            }

            $i = $end + 1;
        }

        return $names;
    }

    /**
     * Whether a path contains any placeholder at all.
     *
     * Prefer this to `parameterNames($path) !== []` on a hot path: it stops at the
     * first one rather than collecting all of them, and for the common caller —
     * "does this route take an argument?" — the answer is needed once.
     */
    public static function hasParameters(string $path): bool
    {
        return self::parameterNames($path) !== [];
    }

    /**
     * Replace `{name}` and `(?P<name>...)` with the caller-supplied text for each.
     *
     * The one substitution path shared by {@see toPattern()} and
     * {@see UrlGenerator}, so a URL is built from the same reading of the grammar
     * that compiled the route it points at.
     *
     * ## Why the compiled spelling is accepted as well as the declared one
     *
     * Because both are legal in a path that reaches here. `{id}` is what an
     * author writes; `(?P<id>[^/]+)` is what `toPattern()` produced from it. A
     * route whose path was replaced by its compiled form — the state a REST route
     * is in after registration — still has to generate a URL, and a replacement
     * that understood only braces would leave the regex in the output.
     *
     * ## Why it is driven by a callback
     *
     * The two callers want different things from the same match. `toPattern()`
     * wants a capture group and no escaping; `UrlGenerator` wants an encoded
     * value and needs to *throw* naming the route when one is missing. Returning
     * a structure and letting each build its own string would mean two more
     * scanners; a callback keeps one.
     *
     * The callback receives the placeholder name and its constraint (null when
     * none was written — including the empty-constraint spelling `{id:}`, which
     * is normalised to null rather than passed through as `''`).
     *
     * @param callable(string, string|null): string $replace
     */
    public static function substitute(string $path, callable $replace): string
    {
        $result = '';
        $length = strlen($path);
        $i = 0;

        while ($i < $length) {
            // A compiled `(?P<name>...)` group, which the brace scan below would
            // walk straight past. Checked first because the two spellings cannot
            // overlap: this branch only fires on `(`, that one only on `{`.
            if ($path[$i] === '('
                && preg_match('/\G\(\?P<[A-Za-z_][A-Za-z0-9_]*>/', $path, $m, 0, $i) === 1
            ) {
                $end = self::findGroupEnd($path, $i + strlen($m[0]));

                if ($end !== null) {
                    $name = substr($m[0], 4, -1);
                    $result .= $replace($name, null);
                    $i = $end + 1;
                    continue;
                }
            }

            if ($path[$i] !== '{') {
                $result .= $path[$i];
                $i++;
                continue;
            }

            $end = self::findPlaceholderEnd($path, $i);

            if ($end === null) {
                $result .= $path[$i];
                $i++;
                continue;
            }

            $name = self::placeholderName($path, $i, $end);

            // Not a name — leave the text alone so the caller's escaping deals
            // with it, rather than swallowing a malformed placeholder.
            if ($name === null) {
                $result .= substr($path, $i, $end - $i + 1);
                $i = $end + 1;
                continue;
            }

            $body = substr($path, $i + 1, $end - $i - 1);
            $constraint = explode(':', $body, 2)[1] ?? null;

            $result .= $replace($name, self::normaliseConstraint($constraint));
            $i = $end + 1;
        }

        return $result;
    }

    /**
     * The placeholder name a `{`…`}` span declares, or null when it is not one.
     *
     * Shared by {@see parameterNames()} and {@see substitute()} so the two agree
     * on what counts as a name — the failure mode being a name the compiler
     * substitutes but no extractor reports, which is invisible until a value goes
     * missing at dispatch.
     */
    private static function placeholderName(string $path, int $start, int $end): ?string
    {
        $body = substr($path, $start + 1, $end - $start - 1);
        $name = explode(':', $body, 2)[0];

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 ? $name : null;
    }

    /**
     * A constraint as written, or null when none was given.
     *
     * `{id:}` splits to an empty string, which is *not* null — so a `?? '[^/]+'`
     * would not catch it, and the group would compile to `(?P<id>)`. That matches
     * the empty string rather than a path segment, and since WordPress anchors
     * every pattern, such a route would also shadow any sibling it is registered
     * before. Normalised here so every caller sees one spelling of "no
     * constraint".
     */
    private static function normaliseConstraint(?string $constraint): ?string
    {
        return $constraint === null || trim($constraint) === '' ? null : $constraint;
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

            $name = self::placeholderName($path, $i, $end);

            if ($name !== null
                && self::normaliseConstraint(explode(':', substr($path, $i + 1, $end - $i - 1), 2)[1] ?? null) !== null
            ) {
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
     * A thin wrapper over {@see substitute()}, which owns the scanning. This
     * method exists to name the direction — the public API is "substitute with
     * whatever you like", and the compiler's own use of it is "with a capture
     * group".
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
        return self::substitute(
            $path,
            // The one place a constraint written as `''` becomes `[^/]+`. A
            // declared `{id}` and an empty-constrained `{id:}` compile to the
            // same group, which is what lets `parameterNames()` treat them alike.
            static fn (string $name, ?string $constraint): string => sprintf(
                '(?P<%s>%s)',
                $name,
                $constraint ?? '[^/]+'
            )
        );
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
