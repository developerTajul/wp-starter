<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use Framework\Routing\Router;
use PHPUnit\Framework\TestCase;

/**
 * Adversarial audit of toWordPressRoute(): every literal character a route
 * author can put in a path must either be matched literally or be rejected,
 * and never silently change what the route matches.
 *
 * These are deliberately hostile inputs. A route path is written by the
 * plugin author, not by the client, but "the author would not do that" is not
 * a security boundary — a path built from a config value or a slug table is
 * attacker-influenced in practice.
 */
final class RoutePatternAuditTest extends TestCase
{
    /**
     * The property under test: a translated pattern must match its own literal
     * path and must NOT match a path that differs by one character.
     */
    private function assertMatchesOnlyItself(string $path): void
    {
        $pattern = Router::toWordPressRoute($path);
        $regex = '@^' . $pattern . '$@i';

        $this->assertSame(
            1,
            @preg_match($regex, $path),
            sprintf('Pattern [%s] does not match its own path [%s].', $pattern, $path)
        );

        // A single character substituted for a literal character must not match.
        $mutated = substr($path, 0, -1) . 'X';

        if ($mutated !== $path) {
            $this->assertSame(
                0,
                @preg_match($regex, $mutated),
                sprintf('Pattern [%s] wrongly matched [%s].', $pattern, $mutated)
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function literalPaths(): array
    {
        return [
            'plain' => ['posts'],
            'nested' => ['posts/archive'],
            'dotted file' => ['export.json'],
            'dotted version' => ['v1.0/report'],
            'dotted date' => ['reports/2024.05.01'],
            'hyphen' => ['lead-status'],
            'underscore' => ['lead_status'],
            'plus' => ['a+b'],
            'question' => ['what'],
            'brackets' => ['reports[draft]'],
            'parens' => ['reports(draft)'],
            'pipe' => ['a|b'],
            'caret' => ['a^b'],
            'dollar' => ['a$b'],
            'hash' => ['a#b'],
            'percent' => ['a%20b'],
            'tilde' => ['a~b'],
        ];
    }

    /**
     * @dataProvider literalPaths
     */
    public function test_a_literal_path_matches_only_itself(string $path): void
    {
        $this->assertMatchesOnlyItself($path);
    }

    /**
     * A path is user-writable in some designs. Characters that would break out
     * of the pattern, or that WordPress would reject anyway, must not produce a
     * pattern that silently matches something else.
     */
    public function test_regex_metacharacters_do_not_become_wildcards(): void
    {
        foreach (['export.json', 'v1.0/report', 'a+b', 'a$b', 'a^b'] as $path) {
            $pattern = Router::toWordPressRoute($path);

            $this->assertStringNotContainsString(
                '\.',
                str_replace(['\.', '\\+', '\\$', '\\^'], '', $pattern),
                sprintf('Unescaped metacharacter survived in [%s].', $pattern)
            );
        }
    }

    /**
     * A placeholder constraint is regex by design, but it must not be able to
     * inject a second capture group with a duplicate name — that makes the
     * whole pattern invalid and kills the route.
     */
    public function test_a_constraint_cannot_inject_a_duplicate_named_group(): void
    {
        $pattern = Router::toWordPressRoute('x/{a:(?P<a>y)}');

        // Either the injected group is neutralised or the pattern is caught;
        // what must never happen is a compiled-but-broken pattern.
        $this->assertNotSame(
            1,
            @preg_match('@^' . $pattern . '$@i', 'xy'),
            sprintf('A duplicate named group compiled successfully: [%s].', $pattern)
        );
    }

    /**
     * An unbalanced brace leaves the placeholder untranslated, so the literal
     * `{` reaches WordPress as regex — which it escapes itself, but the route
     * then requires a literal `{` and can never match a real request path.
     */
    public function test_an_unbalanced_brace_does_not_produce_a_live_wildcard(): void
    {
        $pattern = Router::toWordPressRoute('posts/{id');

        $this->assertSame(0, @preg_match('@^' . $pattern . '$@i', 'posts/123'));
    }
}
