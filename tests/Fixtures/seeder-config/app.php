<?php
declare(strict_types=1);

/**
 * A config whose root namespace is *not* the library default.
 *
 * ## Why this is separate from `tests/Fixtures/config/app.php`
 *
 * That fixture deliberately keeps `'namespace' => 'App'` — it exists to prove
 * prefix and CLI values are derived, and every test that loads it asserts on
 * those. Changing its namespace would change what those tests mean.
 *
 * This one exists for the opposite claim: that
 * {@see \Framework\Database\Seeding\SeederRegistry} reads the root namespace from
 * config instead of hardcoding `App`. That claim can only be tested with a value
 * the default is *not*, which is the same trap the other fixture's docblock
 * describes — `'App'` equals the default, so an assertion satisfied by it passes
 * whether the registry reads config or hardcodes the literal.
 *
 * ## Why the value is unlike anything else in the suite
 *
 * `Rebranded` appears in no other config, no test fixture namespace, and no
 * source file. A hardcoded `App` cannot accidentally match it, and neither can a
 * registry that read the wrong config key.
 *
 * The path guard mirrors the sibling fixture's: `Config::load()` no-ops on a
 * directory that does not exist, so a stale relative path would produce an empty
 * config and an assertion about namespaces that names nothing about the move.
 */
if (!is_dir(__DIR__)) {
    throw new \RuntimeException(
        sprintf('The seeder namespace fixture is not where the caller expected: [%s].', __DIR__)
    );
}

return [
    'name' => 'Seeded Project',
    'env' => 'testing',
    'prefix' => 'seeded_project_',
    // The value under test. Everything else here is present because the
    // framework reads these keys during boot and a fixture that omitted them
    // would fail for reasons unrelated to what it is checking.
    'namespace' => 'Rebranded',
    'views' => 'views',
    'cli_prefix' => 'seeded',
    'rest_namespace' => 'seeded/v1',
    'providers' => [],
];
