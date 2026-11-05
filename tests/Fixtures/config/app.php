<?php
declare(strict_types=1);

/**
 * A rebranded app config, for tests that must prove a value is *derived*.
 *
 * ## Why this exists rather than a Config::set() call
 *
 * `Config` has no setter, and it should not grow one for a test's convenience —
 * a mutable global config is how a suite acquires an order dependency. What it
 * does have is `load()`, which merges a directory's files by filename, so a
 * fixture directory is the supported way to put a different value in front of
 * the code under test.
 *
 * ## Why the prefix is deliberately unlike the default
 *
 * `wp_mini_laravel_` is the library default and also the shipped config, so a
 * test that loads it cannot tell a *derived* value from a hardcoded one — the
 * two are the same string, and an assertion matching the resolved value passes
 * either way. That is not a hypothetical: the first version of the REST adapter's
 * prefix test did exactly this and stayed green against a hardcoded literal.
 *
 * `acme` shares no substring with the default, so a hardcoded value cannot
 * accidentally match. That is the whole point of the value; do not "tidy" it
 * back to the real prefix.
 *
 * ## Why the path guard below is here
 *
 * The tests that load this directory reach it as `__DIR__ . '/../../Fixtures/config'`
 * from `tests/Unit/...`. Relative paths like that break *silently* when a test
 * file moves: `Config::load()` is documented to no-op on a directory that does
 * not exist, so a stale path produces no error, no warning, and an empty config
 * — and the assertion that follows either falls back to a default that happens
 * to match, or fails with a message about prefixes that names nothing about the
 * move. Failing loudly here, once, converts that into the actual problem.
 *
 * `$prefix` keeps the literal in one place for the message below; the array
 * itself still spells it, because a fixture that computes its own values is a
 * fixture that can agree with itself while being wrong.
 */
if (!is_dir(__DIR__)) {
    throw new \RuntimeException(
        sprintf('The rebranded config fixture is not where the caller expected: [%s].', __DIR__)
    );
}

$prefix = 'acme_';

return [
    'name' => 'Acme Leads',
    'env' => 'development',
    'prefix' => $prefix,
    'namespace' => 'App',
    'views' => 'views',
    'cli_prefix' => 'acme',
    'rest_namespace' => 'acme/v1',
    'providers' => [],
];
