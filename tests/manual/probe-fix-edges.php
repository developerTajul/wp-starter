<?php
declare(strict_types=1);

/**
 * Edge cases for the review fixes, checked against the framework directly.
 *
 * Complements tests/manual/verify-review-fixes.php (which needs WordPress): this
 * one drives the pure-PHP pieces — the placeholder scanner and the bool
 * normaliser — where the interesting inputs are string shapes rather than
 * database state.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/tests/bootstrap.php';

use Framework\Routing\Router;

$failures = 0;

$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    printf("%s  %-62s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);

    if (!$ok) {
        $failures++;
    }
};

// ------------------------------------------------------- placeholder scanner

echo "--- placeholder scanner ---\n";

$cases = [
    // path                        expected compiled pattern
    'a/{id:\d+}'               => 'a/(?P<id>\d+)',
    'a/{id:[0-9]{1,3}}'        => 'a/(?P<id>[0-9]{1,3})',
    'a/{id:[0-9]{1,3}}/b/{c}'  => 'a/(?P<id>[0-9]{1,3})/b/(?P<c>[^/]+)',
    'a/{x}/{y}'                => 'a/(?P<x>[^/]+)/(?P<y>[^/]+)',
    // A colon inside a character class or a group is part of the constraint.
    'a/{v:[a:z]+}'             => 'a/(?P<v>[a:z]+)',
    'a/{v:(?:ab|cd):?}'        => 'a/(?P<v>(?:ab|cd):?)',
];

foreach ($cases as $path => $expected) {
    $actual = Router::toWordPressRoute($path);
    $check($path, $actual === $expected, $actual === $expected ? '' : "got: {$actual}");
}

// An empty constraint. `{id:}` is malformed; it must not produce a pattern that
// matches the empty string while looking like a valid route.
$empty = Router::toWordPressRoute('a/{id:}');
printf("%s  %-62s -> %s\n", 'INFO', 'a/{id:} (empty constraint)', $empty);
$check(
    'a/{id:} does not compile to a group matching nothing',
    !str_contains($empty, '(?P<id>)'),
    'empty constraint must fall back to the default, not vanish'
);

// An unterminated placeholder must be escaped literally, not dropped.
$unterminated = Router::toWordPressRoute('a/{id');
$check('a/{id (unterminated) is escaped literally', $unterminated === 'a/\{id', $unterminated);

// Adjacent placeholders.
$adjacent = Router::toWordPressRoute('a/{x}{y}');
$check(
    'adjacent placeholders both survive',
    $adjacent === 'a/(?P<x>[^/]+)(?P<y>[^/]+)',
    $adjacent
);

// A literal `}` after a placeholder.
$trailing = Router::toWordPressRoute('a/{x}}');
$check('a literal trailing } is escaped', $trailing === 'a/(?P<x>[^/]+)\}', $trailing);

// ------------------------------------------------------------ bool normalising

echo "\n--- bool coercion (through the resolver) ---\n";

$container = new DevLinkStudios\WPServiceContainer\Container();
$resolver = new Framework\Routing\RouteResolver($container);

$reflect = new ReflectionMethod($resolver, 'normalize');
$reflect->setAccessible(true);

$boolType = new ReflectionNamedType();
// Build a real bool-typed parameter rather than faking the ReflectionType.
$fn = static function (bool $b): void {};
$boolType = (new ReflectionFunction($fn))->getParameters()[0]->getType();

$intType = (new ReflectionFunction(static function (int $i): void {}))->getParameters()[0]->getType();

foreach ([
    ['false', false], ['FALSE', false], ['False', false], ['0', false], [0, false],
    ['', false], ['true', true], ['TRUE', true], ['1', true], [1, true],
    [true, true], [false, false],
] as [$input, $expected]) {
    $actual = $reflect->invoke($resolver, $boolType, $input);
    $check(
        'bool: ' . var_export($input, true) . ' -> ' . var_export($expected, true),
        $actual === $expected,
        $actual === $expected ? '' : 'got ' . var_export($actual, true)
    );
}

// An int parameter must be left completely alone.
$check('int: "3" is untouched', $reflect->invoke($resolver, $intType, '3') === '3');
$check('int: 3 is untouched', $reflect->invoke($resolver, $intType, 3) === 3);

// A value that is not a recognised spelling must pass through so accepts()
// decides, rather than being silently coerced.
$odd = $reflect->invoke($resolver, $boolType, 'yes');
$check('bool: "yes" passes through uncoerced', $odd === 'yes', var_export($odd, true));

echo "\n" . ($failures === 0 ? "All checks passed.\n" : "{$failures} check(s) FAILED.\n");

exit($failures === 0 ? 0 : 1);
