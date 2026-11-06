<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/tests/stubs/wordpress.php';

use Framework\Console\StubLocator;
use Framework\Console\StubNotFoundException;
use Tests\Support\FakeWpdb;
use Tests\Support\MigrationWorkspace;
use Tests\Support\RecordingMigration;
use Tests\Support\WpStub;

WpStub::reset();

// ---------------------------------------------------------------- StubLocator
$empty = dirname(__DIR__, 2) . '/.probe-tmp/nostubs';
mkdir($empty, 0755, true);

$locator = new StubLocator($empty);

foreach (['controller.stub', 'migration.stub', 'command.stub'] as $name) {
    printf("framework fallback %-18s -> %s (%s)\n", $name, $locator->source($name), $locator->resolve($name));
}

// project override wins
mkdir($empty . '/stubs', 0755, true);
file_put_contents($empty . '/stubs/controller.stub', 'overridden');
$overridden = new StubLocator($empty);
printf("project override       -> %s | contents=%s\n",
    $overridden->source('controller.stub'),
    file_get_contents($overridden->resolve('controller.stub'))
);

// missing names both paths
try {
    $overridden->resolve('nope.stub');
    echo "BUG: no throw\n";
} catch (StubNotFoundException $e) {
    echo "missing stub           -> throws, names both paths: "
        . (substr_count($e->getMessage(), "\n  ") === 2 ? 'yes' : 'NO') . "\n";
}

// ------------------------------------------------------- make:command naming
$ws = sys_get_temp_dir() . '/wpml-probe-make-' . bin2hex(random_bytes(4));
mkdir($ws . '/app/Console/Commands', 0755, true);

$container = new DevLinkStudios\WPServiceContainer\Container();
$container->instance('path.base', $ws);
$GLOBALS['wpml_probe_container'] = $container;

foreach (['SendDigest', 'SendDigestCommand', 'SyncHTTPCache', 'Check'] as $arg) {
    $cmd = new class($ws, $arg) extends Framework\Console\Commands\MakeCommandCommand {
        public array $messages = [];
        public function __construct(private string $ws, private string $arg) {}
        protected function projectPath(): string { return $this->ws; }
        protected function getTargetPath(string $c): string { return $this->ws . '/app/Console/Commands/' . $c . '.php'; }
        protected function success(string $m): void { $this->messages[] = $m; }
        protected function error(string $m): void { $this->messages[] = $m; }
        protected function info(string $m): void { $this->messages[] = $m; }
    };
    $cmd->handle([$arg], []);
    $file = $ws . '/app/Console/Commands/' . $arg . '.php';
    if (is_file($file)) {
        preg_match("/return config\('app\.cli_prefix'[^;]*\) \. ' ([^']+)'/", (string) file_get_contents($file), $m);
        printf("make:command %-20s -> class %-24s verb=%s\n", $arg, $arg, $m[1] ?? '??');
    } else {
        printf("make:command %-20s -> FAILED: %s\n", $arg, implode('|', $cmd->messages));
    }
}

// ----------------------------------------------------------- migrate:fresh
$workspace = new MigrationWorkspace();
$db = new FakeWpdb();
$migrator = new Framework\Database\Migrator($workspace->path(), $db);

$workspace->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
$workspace->write('2026_11_05_120000_create_leads_table', RecordingMigration::class);

$applied = $migrator->runPending();
printf("\napplied %d, batches=%s, tracking table exists=%s\n",
    count($applied), json_encode($migrator->batches()),
    $db->tableExists('wp_plugin_migrations') ? 'yes' : 'no');

// seed a fake "migration-created" table so we can see it dropped
$db->createTable('wp_users_table');
$db->seed('wp_plugin_migrations', ['migration' => '2026_11_05_100000_create_users_table', 'batch' => 1]);

$cmd = new class($migrator) extends Framework\Database\Commands\MigrateFreshCommand {
    public array $messages = [];
    protected function success(string $m): void { $this->messages[] = $m; }
    protected function error(string $m): void { $this->messages[] = $m; }
    protected function info(string $m): void { $this->messages[] = $m; }
    protected function warning(string $m): void { $this->messages[] = $m; }
};

$cmd->handle([], []);
echo "fresh without --force  -> " . (str_contains(implode('|', $cmd->messages), '--force') ? 'refused' : 'NOT REFUSED') . "\n";
echo "  tables still there   -> " . ($db->tableExists('wp_plugin_migrations') ? 'yes' : 'no') . "\n";

$cmd2 = new class($migrator) extends Framework\Database\Commands\MigrateFreshCommand {
    public array $messages = [];
    protected function success(string $m): void { $this->messages[] = $m; }
    protected function error(string $m): void { $this->messages[] = $m; }
    protected function info(string $m): void { $this->messages[] = $m; }
    protected function warning(string $m): void { $this->messages[] = $m; }
};
$cmd2->handle([], ['force' => '1']);
echo "fresh with --force     -> " . end($cmd2->messages) . "\n";
echo "  batches after fresh  -> " . json_encode($migrator->batches()) . " (expect [1] - restarted)\n";
echo "  rows recorded        -> " . count($db->rows('wp_plugin_migrations')) . "\n";

// --force=0 must NOT authorise
$cmd3 = new class($migrator) extends Framework\Database\Commands\MigrateFreshCommand {
    public array $messages = [];
    protected function success(string $m): void { $this->messages[] = $m; }
    protected function error(string $m): void { $this->messages[] = $m; }
    protected function info(string $m): void { $this->messages[] = $m; }
    protected function warning(string $m): void { $this->messages[] = $m; }
};
$cmd3->handle([], ['force' => '0']);
echo "fresh --force=0        -> " . (str_contains(implode('|', $cmd3->messages), 'refused') || str_contains(implode('|', $cmd3->messages), '--force') ? 'refused (correct)' : 'RAN (BUG)') . "\n";

// --------------------------------------------------------- migrate:refresh
$workspace2 = new MigrationWorkspace();
$db2 = new FakeWpdb();
$migrator2 = new Framework\Database\Migrator($workspace2->path(), $db2);
$workspace2->write('2026_11_05_100000_create_users_table', RecordingMigration::class);
$migrator2->runPending();
$tablesBefore = $migrator2->batches();

$cmd4 = new class($migrator2) extends Framework\Database\Commands\MigrateRefreshCommand {
    public array $messages = [];
    protected function success(string $m): void { $this->messages[] = $m; }
    protected function error(string $m): void { $this->messages[] = $m; }
    protected function info(string $m): void { $this->messages[] = $m; }
    protected function warning(string $m): void { $this->messages[] = $m; }
};
$cmd4->handle([], []);
echo "\nrefresh batches before -> " . json_encode($tablesBefore) . "\n";
echo "refresh result         -> " . end($cmd4->messages) . "\n";
echo "refresh batches after  -> " . json_encode($migrator2->batches()) . " (expect [2] - new batch, not reused)\n";

$workspace->cleanup();
$workspace2->cleanup();
