<?php
declare(strict_types=1);

/**
 * Does `migrate:rollback --batch=<n>` reach the migrations a *partially* failed
 * batch recorded?
 *
 * The README claims it does. This checks it, because the distinction between
 * "failed partway" (rows exist, so --batch works) and "failed immediately" (no
 * rows, so nothing to reverse) is the whole point of the caveat.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/tests/bootstrap.php';

use Framework\Database\Migrator;
use Tests\Support\FakeWpdb;
use Tests\Support\MigrationWorkspace;
use Tests\Support\RecordingMigration;
use Tests\Support\ThrowingMigration;

$failures = 0;

$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    printf("%s  %-56s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);

    if (!$ok) {
        $failures++;
    }
};

// --- Case A: a batch that fails PARTWAY. Rows exist under its number.
$ws = new MigrationWorkspace();
$db = new FakeWpdb();

$ws->write('2026_11_05_100000_create_alpha_table', RecordingMigration::class);
$ws->write('2026_11_05_110000_create_beta_table', ThrowingMigration::class);
$ws->write('2026_11_05_120000_create_gamma_table', RecordingMigration::class);

$migrator = new Migrator($ws->path(), $db);

try {
    $migrator->runPending();
} catch (\Throwable) {
    // Expected: beta throws.
}

$recorded = $db->get_results("SELECT `migration`,`batch` FROM `wp_plugin_migrations`", ARRAY_A);
$check('a partial failure records the migrations that completed', count($recorded) === 1, json_encode($recorded));

$batch = (int) ($recorded[0]['batch'] ?? 0);
$reverted = $migrator->rollbackBatch($batch);

$check(
    'rollback --batch=<n> reaches a partially failed batch',
    $reverted === ['2026_11_05_100000_create_alpha_table'],
    json_encode($reverted)
);
$check('the rows are gone afterwards', $db->rows('wp_plugin_migrations') === []);

// --- Case B: a batch that fails IMMEDIATELY. No rows at all.
//
// WpStub's options are process-global and outlive a workspace, so the batch
// high-water mark has to be cleared or this workspace inherits case A's and
// alpha lands in batch 2 — which would make the assertions below pass while
// testing the wrong thing.
Tests\Support\WpStub::reset();

$ws2 = new MigrationWorkspace();
$db2 = new FakeWpdb();

$ws2->write('2026_11_05_100000_create_alpha_table', RecordingMigration::class);
$migrator2 = new Migrator($ws2->path(), $db2);
$migrator2->runPending();

$ws2->write('2026_11_05_110000_create_beta_table', ThrowingMigration::class);

try {
    $migrator2->runPending();
} catch (\Throwable) {
    // Expected.
}

$rows = $db2->get_results("SELECT `migration`,`batch` FROM `wp_plugin_migrations`", ARRAY_A);
$check(
    'an immediate failure records nothing new, and alpha stays in batch 1',
    $rows === [['migration' => '2026_11_05_100000_create_alpha_table', 'batch' => '1']],
    json_encode($rows)
);

$check(
    'the burned number (2) is not reachable by rollback',
    (int) get_option('wp_plugin_migrations_batch') === 2,
    'high-water = ' . var_export(get_option('wp_plugin_migrations_batch'), true)
);

// The burned number is unreachable: rollback targets the newest *recorded* batch.
$reverted2 = $migrator2->rollback();
$check(
    'plain rollback targets the previous recorded batch, not the burned one',
    $reverted2 === ['2026_11_05_100000_create_alpha_table'],
    json_encode($reverted2)
);

echo "\n" . ($failures === 0 ? "All checks passed.\n" : "{$failures} check(s) FAILED.\n");

exit($failures === 0 ? 0 : 1);
