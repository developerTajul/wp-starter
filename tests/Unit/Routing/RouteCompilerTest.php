<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use Framework\Routing\Route;
use Framework\Routing\RouteCompiler;
use Framework\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * The compiler is the one place a route path becomes a regex, and the one place
 * route ordering is decided. Both the REST router and the web adapter depend on
 * it agreeing with itself, so the contract is pinned here rather than only
 * through whichever router happens to exercise it first.
 *
 * A second implementation of this grammar is the failure this class exists to
 * prevent: it would still work for the paths its own tests cover while
 * disagreeing on the awkward ones — and the symptom would be a route that 404s
 * in one domain and resolves in the other, with nothing raised at registration.
 */
final class RouteCompilerTest extends TestCase
{
    /**
     * `Router::toWordPressRoute()` predates the compiler and is still the spelling
     * the existing tests and the REST adapter use. It must be a pure delegation,
     * not a survivor of the old parser — otherwise the two drift apart, which is
     * exactly what extracting this class was meant to stop.
     */
    public function test_the_router_entry_point_agrees_with_the_compiler(): void
    {
        $paths = [
            'posts',
            'posts/{id}',
            'posts/{id:\d+}',
            'posts/{id:[0-9]{1,3}}',
            'posts/{id:((\d+))}',
            'posts/{id:(?:a(b))}',
            'export.json',
            'v1.0/{id:\d+}/{slug:[a-z-]+}',
            'weird/{not a name}',
            'posts/(draft',
            'posts/{id:}',
        ];

        foreach ($paths as $path) {
            $this->assertSame(
                RouteCompiler::toPattern($path),
                Router::toWordPressRoute($path),
                sprintf('The two translations disagree on [%s].', $path)
            );
        }
    }

    /**
     * A literal dot is a regex wildcard unless escaped, so `file.json` would also
     * match `fileXjson` and both would dispatch the same route.
     */
    public function test_metacharacters_outside_placeholders_are_escaped(): void
    {
        $this->assertSame('export\.json', RouteCompiler::toPattern('export.json'));
    }

    public function test_a_placeholder_becomes_a_named_capture_group(): void
    {
        $this->assertSame('posts/(?P<id>[^/]+)', RouteCompiler::toPattern('posts/{id}'));
    }

    /**
     * A constraint is a regex and may contain braces. A `[^}]+` scan truncates
     * `[0-9]{1,3}` at the quantifier's brace, producing a pattern that matches
     * nothing — a dead route, silently.
     */
    public function test_a_quantifier_inside_a_constraint_survives(): void
    {
        $pattern = RouteCompiler::toPattern('posts/{id:[0-9]{1,3}}');

        $this->assertSame('posts/(?P<id>[0-9]{1,3})', $pattern);
        $this->assertSame(1, preg_match('@^' . $pattern . '$@i', 'posts/42'));
        $this->assertSame(0, preg_match('@^' . $pattern . '$@i', 'posts/1234'));
    }

    /**
     * An empty constraint must fall back rather than compile to `(?P<id>)`, which
     * matches the empty string and would shadow a sibling route.
     */
    public function test_an_empty_constraint_falls_back_to_a_segment(): void
    {
        $this->assertSame('posts/(?P<id>[^/]+)', RouteCompiler::toPattern('posts/{id:}'));
        $this->assertSame('posts/(?P<id>[^/]+)', RouteCompiler::toPattern('posts/{id:   }'));
    }

    // ----------------------------------------------------------------- ordering

    /**
     * WordPress matches registered patterns in order and takes the first hit, so
     * a literal route must sort ahead of a parameterised one that would swallow
     * it — otherwise `posts/latest` dispatches `show` with id="latest", which is
     * a wrong action rather than a 404.
     */
    public function test_a_literal_route_sorts_before_a_parameterised_one(): void
    {
        $sorted = RouteCompiler::sortBySpecificity([
            Route::make(['GET'], 'posts/{id}'),
            Route::make(['GET'], 'posts/latest'),
        ]);

        $this->assertSame('posts/latest', $sorted[0]->getPath());
        $this->assertSame('posts/{id}', $sorted[1]->getPath());
    }

    /**
     * With the same literal count, a constrained placeholder is more specific
     * than a bare one and must be tried first.
     */
    public function test_a_constrained_placeholder_sorts_before_a_bare_one(): void
    {
        $sorted = RouteCompiler::sortBySpecificity([
            Route::make(['GET'], 'posts/{slug}'),
            Route::make(['GET'], 'posts/{id:\d+}'),
        ]);

        $this->assertSame('posts/{id:\d+}', $sorted[0]->getPath());
    }

    /**
     * More literal segments wins outright, regardless of how many placeholders
     * the other path carries.
     */
    public function test_more_literal_segments_sorts_first(): void
    {
        $sorted = RouteCompiler::sortBySpecificity([
            Route::make(['GET'], '{a}/{b}/{c}'),
            Route::make(['GET'], 'posts/latest/edit'),
        ]);

        $this->assertSame('posts/latest/edit', $sorted[0]->getPath());
    }

    /**
     * The input is keyed by "METHOD path" in every adapter, so the sorter has to
     * accept a string-keyed array and re-index it. A sorter that assumed a list
     * would drop or reorder the routes it was handed.
     */
    public function test_a_string_keyed_route_table_is_sorted_and_reindexed(): void
    {
        $sorted = RouteCompiler::sortBySpecificity([
            'GET posts/{id}' => Route::make(['GET'], 'posts/{id}'),
            'GET posts/latest' => Route::make(['GET'], 'posts/latest'),
        ]);

        $this->assertSame(['posts/latest', 'posts/{id}'], array_column(
            array_map(static fn (Route $r): array => ['path' => $r->getPath()], $sorted),
            'path'
        ));
        $this->assertSame([0, 1], array_keys($sorted), 'The result must be a list.');
    }

    public function test_sorting_an_empty_table_is_an_empty_list(): void
    {
        $this->assertSame([], RouteCompiler::sortBySpecificity([]));
    }

    /**
     * The names `parameterNames()` reports are exactly the names `toPattern()`
     * compiled.
     *
     * ## The property, and why it is asserted as a property
     *
     * Extraction and compilation answer the same question — "where does a
     * placeholder end?" — so a path that compiles to *n* capture groups must
     * report *n* names, in the same order. Checking that as a relation over a
     * table of awkward paths, rather than asserting a literal list per path, is
     * what makes it a claim about the grammar rather than about eleven strings
     * somebody transcribed.
     *
     * ## The defect it exists to pin
     *
     * Three modules each carried a private regex for this grammar and only one of
     * them was the compiler. On `posts/{id:}` the two `\{...(?::[^}]+)?\}` copies
     * — this one's ancestors in `Route` and `UrlGenerator` — matched *nothing*:
     * the pattern required a character after the `:`, and the compiler, reading
     * the same path, produced a working `(?P<id>[^/]+)`. So the route dispatched
     * correctly while `Route::getParameterNames()` returned `[]`, the binding step
     * had no name to bind and dropped the argument, and `UrlGenerator` rejected a
     * parameter the caller had supplied as "unused". Nothing raised.
     *
     * A green suite did not catch it because nothing compared the two answers.
     * This test is that comparison.
     */
    public function test_extracted_names_agree_with_the_compiled_pattern(): void
    {
        $paths = [
            'posts',
            'posts/{id}',
            'posts/{id:\d+}',
            'posts/{id:[0-9]{1,3}}',
            'posts/{id:}',
            'posts/{id:   }',
            'posts/{id:((\d+))}',
            'posts/{id:(?:a(b))}',
            'posts/{a}/{b}',
            'posts/{id}/comments/{comment}',
            'v1.0/{id:\d+}/{slug:[a-z-]+}',
            'export.json',
            'weird/{not a name}',
            'posts/(draft',
        ];

        foreach ($paths as $path) {
            $names = RouteCompiler::parameterNames($path);

            $this->assertSame(
                count($names),
                preg_match_all('/\(\?P<[A-Za-z_][A-Za-z0-9_]*>/', RouteCompiler::toPattern($path)),
                sprintf(
                    'The compiler built a different number of capture groups than parameterNames() reported '
                    . "for [%s]. A name the compiler substitutes but no extractor reports is invisible until a "
                    . 'route dispatches with an argument missing.',
                    $path
                )
            );

            // And the names themselves are the group names, in order.
            preg_match_all('/\(\?P<([A-Za-z_][A-Za-z0-9_]*)>/', RouteCompiler::toPattern($path), $m);
            $this->assertSame($names, $m[1], sprintf('The reported names are not the compiled ones for [%s].', $path));
        }
    }

    /**
     * The empty-constraint spelling yields a named parameter, not none.
     *
     * Singled out from the table above because it is the case that actually
     * diverged in production code, and a failure here should name the defect
     * rather than report a count mismatch.
     */
    public function test_an_empty_constraint_still_yields_a_parameter_name(): void
    {
        $this->assertSame(['id'], RouteCompiler::parameterNames('posts/{id:}'));
        $this->assertSame(['id'], RouteCompiler::parameterNames('posts/{id:   }'));
    }

    /**
     * A constrained placeholder nested in a longer path is reported once, not
     * twice and not zero times.
     *
     * The quantifier's braces are the trap: a name-plus-constraint scan that
     * stopped at the first `}` would see `{id:[0-9]` and then treat `{1,3}` as a
     * second placeholder with a non-name body. The count is what catches that —
     * one group compiled, one name reported.
     */
    public function test_a_quantified_constraint_yields_exactly_one_name(): void
    {
        $this->assertSame(['id'], RouteCompiler::parameterNames('posts/{id:[0-9]{1,3}}'));
        $this->assertSame(['id'], RouteCompiler::parameterNames('posts/{id:[0-9]{1,3}}/edit'));
    }

    /**
     * A name that is not a name is not reported.
     *
     * `parameterNames()` and `substitute()` share the name check, so a body the
     * compiler leaves verbatim as literal text must not be extracted as a
     * parameter — otherwise the binding step would look for a request value for a
     * placeholder that was never compiled into a group.
     */
    public function test_a_malformed_placeholder_yields_no_name(): void
    {
        $this->assertSame([], RouteCompiler::parameterNames('weird/{not a name}'));
        $this->assertSame([], RouteCompiler::parameterNames('weird/{1starts_with_a_digit}'));
        $this->assertSame([], RouteCompiler::parameterNames('posts/(draft'));
        $this->assertSame([], RouteCompiler::parameterNames('posts/unclosed/{id'));
    }

    /**
     * `hasParameters()` agrees with the list it short-circuits.
     *
     * The two are the same question asked two ways, and a `hasParameters()` that
     * walked a different scan would give a caller "this route takes an argument"
     * followed by an empty name list to bind it from.
     */
    public function test_has_parameters_agrees_with_the_name_list(): void
    {
        foreach (['posts', 'posts/{id}', 'posts/{id:}', 'weird/{not a name}', 'posts/{id}/{x}'] as $path) {
            $this->assertSame(
                RouteCompiler::parameterNames($path) !== [],
                RouteCompiler::hasParameters($path),
                sprintf('hasParameters() disagrees with parameterNames() on [%s].', $path)
            );
        }
    }

    /**
     * `substitute()` understands both spellings of a placeholder.
     *
     * `{id}` is what an author writes; `(?P<id>[^/]+)` is what `toPattern()`
     * produced. A path that reached a caller through a compilation step — the
     * state a REST route is in after registration — has to be substitutable too,
     * or a URL generated from it would contain the regex verbatim.
     */
    public function test_substitute_handles_the_declared_and_compiled_spellings(): void
    {
        $replace = static fn (string $name, ?string $constraint): string => '[' . $name . ']';

        $this->assertSame('posts/[id]', RouteCompiler::substitute('posts/{id}', $replace));
        $this->assertSame('posts/[id]', RouteCompiler::substitute('posts/{id:[0-9]{1,3}}', $replace));
        $this->assertSame('posts/[id]', RouteCompiler::substitute('posts/(?P<id>[^/]+)', $replace));

        // A nested group inside the compiled spelling has to be spanned whole, or
        // the substitution leaves the group's tail behind as literal text.
        $this->assertSame('posts/[id]', RouteCompiler::substitute('posts/(?P<id>((\d+)))', $replace));
        $this->assertSame('posts/[id]', RouteCompiler::substitute('posts/(?P<id>(?:a(b)))', $replace));

        // Both spellings in one path, in order.
        $this->assertSame(
            'a/[x]/b/[y]',
            RouteCompiler::substitute('a/{x}/b/(?P<y>[^/]+)', $replace)
        );
    }

    /**
     * `substitute()` hands the callback the constraint, normalised.
     *
     * The callback is the only place a caller can tell "no constraint" from "an
     * empty one", and it must not have to: `{id}` and `{id:}` mean the same thing
     * to this compiler, so they mean the same thing to a callback. Anything else
     * would push the grammar's edge case into every caller.
     */
    public function test_substitute_normalises_the_constraint_for_the_callback(): void
    {
        $seen = [];

        RouteCompiler::substitute(
            'a/{x}/b/{y:\d+}/c/{z:}/d/{w:   }',
            static function (string $name, ?string $constraint) use (&$seen): string {
                $seen[$name] = $constraint;

                return '';
            }
        );

        $this->assertSame(
            ['x' => null, 'y' => '\d+', 'z' => null, 'w' => null],
            $seen,
            'A callback must not have to know that `{id:}` and `{id}` are the same placeholder.'
        );
    }

    /**
     * Literal text around placeholders is passed through untouched.
     *
     * `substitute()` is a scanner, not an escaper — that is `toPattern()`'s extra
     * step. A substitution that also quoted its literals would double-escape the
     * path on its way to `escapeNonPlaceholders()`.
     */
    public function test_substitute_leaves_literal_text_alone(): void
    {
        $replace = static fn (string $name, ?string $constraint): string => 'X';

        $this->assertSame('export.json/X', RouteCompiler::substitute('export.json/{id}', $replace));
        $this->assertSame('a(b)/X', RouteCompiler::substitute('a(b)/{id}', $replace));
        $this->assertSame('weird/{not a name}/X', RouteCompiler::substitute('weird/{not a name}/{id}', $replace));
    }

    /**
     * A compiled group that never closes is not substituted.
     *
     * The mirror of the unclosed-brace case. Bailing out leaves the text for the
     * caller's escaping, which is the safe direction: treating an unterminated
     * group as a placeholder would consume the rest of the path.
     */
    public function test_an_unclosed_compiled_group_is_left_alone(): void
    {
        $replace = static fn (string $name, ?string $constraint): string => 'X';

        $this->assertSame('posts/(?P<id>[^/]+', RouteCompiler::substitute('posts/(?P<id>[^/]+', $replace));
    }
}
