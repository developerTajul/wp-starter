<?php
declare(strict_types=1);

/**
 * A config whose seeder namespace is unique to the integration seeder tests.
 *
 * ## The problem this solves
 *
 * {@see \Framework\Database\Seeding\SeederRegistry} names a seeder class from its
 * **filename** and `require`s the file. PHP's class table is process-wide and
 * PHPUnit runs the whole suite in one process, so two files declaring the same
 * class — in two different temporary directories, or one in a temp directory and
 * one in the project itself — is a fatal: "Cannot declare class … because the
 * name is already in use". `require_once` guards by *path* and does not help.
 *
 * `SeederRegistryTest` and `DbSeedCommandTest` dodge this by baking a random
 * suffix into every fixture class name. That works until a fixture has to keep a
 * *fixed* name because the command selects it by name — `DatabaseSeeder`, which
 * `db:seed` looks up as `database`. There is exactly one such name, and the
 * project itself now declares it in `database/seeders/`, so a temp-dir
 * `DatabaseSeeder` collides with the real one the moment any other test loads it.
 *
 * Moving the *namespace* solves it properly. A fixture declares
 * `Integration\\Database\\Seeders\\DatabaseSeeder`, which is a different class
 * from `App\\Database\\Seeders\\DatabaseSeeder` no matter what either file is
 * called — so the fixed name stays fixed, the suffix hack is no longer load
 * bearing for it, and the two tests can coexist in either order.
 *
 * ## Why the value is unlike anything else in the suite
 *
 * `Integration\Database\Seeders` appears in no source file, no other fixture and
 * no other test's expectations. A registry that read the wrong config key, or
 * fell back to the default, cannot accidentally produce it — which also makes
 * this fixture double as proof that the namespace really is read from config
 * rather than hardcoded, the same claim `tests/Fixtures/seeder-config` exists for
 * on the unit side.
 *
 * Note the value below is the *root* — the registry appends `\Database\Seeders`
 * itself, and the class the fixtures declare is the two joined.
 *
 * The path guard mirrors both sibling fixtures': `Config::load()` no-ops on a
 * missing directory, so a stale relative path would produce an empty config and
 * an assertion that names nothing about the move.
 */
if (!is_dir(__DIR__)) {
    throw new \RuntimeException(
        sprintf('The seeder integration fixture is not where the caller expected: [%s].', __DIR__)
    );
}

return [
    'name' => 'Seeder Integration',
    'env' => 'testing',
    'prefix' => 'seeder_integration_',
    // The value under test — the *root* namespace only.
    //
    // {@see \Framework\Database\Seeding\SeederRegistry::namespace()} appends
    // `\Database\Seeders` to whatever this holds, so declaring the full path here
    // would produce `Integration\Database\Database\Seeders` — a class name that
    // matches no file, which discovery skips in silence. The failure presents as
    // "no seeders found" and names the directory rather than the namespace, so it
    // is worth knowing that this key is a root and not a path.
    'namespace' => 'Integration',
    'views' => 'views',
    'cli_prefix' => 'wp_mini',
    'rest_namespace' => 'seeder-integration/v1',
    'providers' => [],
];
