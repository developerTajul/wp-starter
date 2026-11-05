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
}
