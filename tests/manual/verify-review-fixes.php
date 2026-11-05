<?php
declare(strict_types=1);

/**
 * Manual verification of the review fixes against a real WordPress install.
 *
 * Not part of the PHPUnit suite: it needs a loaded WordPress and a live MySQL,
 * and it asserts things a fake `$wpdb` cannot model faithfully — LIKE wildcard
 * semantics in particular, which is the whole point of one of the fixes.
 *
 * Run with:  php tests/manual/verify-review-fixes.php
 * Cleans up every table, option and file it creates.
 */

define('WP_USE_THEMES', false);
// tests/manual/ -> tests/ -> plugin/ -> plugins/ -> wp-content/ -> install root
require_once dirname(__DIR__, 5) . '/wp-load.php';

global $wpdb;

$table = $wpdb->prefix . 'plugin_migrations';
$probe = $wpdb->prefix . 'verify_probe';
$decoy = str_replace('_', 'X', $table);

$migrationFile = dirname(__DIR__, 2) . '/database/migrations/2026_11_05_180000_create_verify_probe_table.php';

$failures = 0;

$check = static function (string $label, bool $ok) use (&$failures): void {
    printf("%s  %s\n", $ok ? 'PASS' : 'FAIL', $label);

    if (!$ok) {
        $failures++;
    }
};

// ---------------------------------------------------------------- LIKE escaping

$wpdb->query("DROP TABLE IF EXISTS `{$table}`");
$wpdb->query("DROP TABLE IF EXISTS `{$decoy}`");
$wpdb->query("CREATE TABLE `{$decoy}` (id bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (id))");

// The pattern the framework used to send: every `_` acting as a wildcard.
$unescaped = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

// The pattern it sends now.
$escaped = $wpdb->get_var($wpdb->prepare(
    'SHOW TABLES LIKE %s',
    str_replace(['\\', '_', '%'], ['\\\\', '\\_', '\\%'], $table)
));

echo "--- LIKE escaping (real MySQL) ---\n";
printf("  unescaped pattern matched: %s\n", var_export($unescaped, true));
printf("  escaped pattern matched:   %s\n", var_export($escaped, true));

$check('a sibling table no longer satisfies the existence probe', $escaped === null);
$check('the unescaped pattern did match the sibling (the bug was real)', $unescaped !== null);

$wpdb->query("DROP TABLE IF EXISTS `{$decoy}`");

// ------------------------------------------------------------ full migrate cycle

echo "\n--- migrate / status / rollback (real MySQL) ---\n";

file_put_contents($migrationFile, <<<'MIG'
<?php
use Framework\Database\Migration;
final class CreateVerifyProbeTable extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $wpdb->query(sprintf('CREATE TABLE IF NOT EXISTS `%s` (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (id))', $wpdb->prefix . 'verify_probe'));
    }
    public function down(): void
    {
        global $wpdb;
        $wpdb->query(sprintf('DROP TABLE IF EXISTS `%s`', $wpdb->prefix . 'verify_probe'));
    }
}
MIG);

$migrator = app(\Framework\Database\Migrator::class);

$ran = $migrator->runPending();
$check('the migration ran', $ran !== []);
$check('the tracking table was created', (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)));
$check(
    'the tracking table has the expected columns',
    $wpdb->get_col("SHOW COLUMNS FROM `{$table}`") === ['id', 'migration', 'batch']
);
$check('the migration created its table', (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $probe)));

// getStatus() keys its rows by identifier, so index [0] would be undefined —
// the status command iterates with foreach, which is indifferent to the keys.
$status = array_values($migrator->getStatus());
$check('status reports the migration as ran', ($status[0]['ran'] ?? false) === true);
$check('status reports its batch', ($status[0]['batch'] ?? null) === 1);

$reverted = $migrator->rollback();
$check('rollback reversed it', $reverted !== []);
$check('rollback dropped the table', !$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $probe)));
$check('rollback left no rows behind', (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`") === 0);

// -------------------------------------------------------------------- cleanup

$wpdb->query("DROP TABLE IF EXISTS `{$probe}`");
$wpdb->query("DROP TABLE IF EXISTS `{$table}`");
$wpdb->query("DROP TABLE IF EXISTS `{$decoy}`");
delete_option($wpdb->prefix . 'plugin_migrations_batch');
@unlink($migrationFile);

echo "\n" . ($failures === 0 ? "All checks passed.\n" : "{$failures} check(s) FAILED.\n");

exit($failures === 0 ? 0 : 1);
