<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Covers the hybrid rebrander: static structural rewriting plus the dynamic
 * `config/app.php` fallbacks and `.env` synchronisation.
 *
 * ## Why these run against a real temp copy
 *
 * The rebrander rewrites files on disk and reads the tree it is standing in.
 * A test double for the filesystem would assert that the code calls the methods
 * the test already assumes it calls — which is exactly the assertion that was
 * wrong when the `framework/` boundary silently broke. So each case materialises
 * the files it needs in a scratch directory, runs the real script over it, and
 * reads the bytes back.
 *
 * The script is invoked as a subprocess rather than included, because it calls
 * `exit()` on failure and defines a global constant — either of which would take
 * the PHPUnit process with it.
 */
final class RebranderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/wpmini-rebrand-' . bin2hex(random_bytes(6));

        mkdir($this->root . '/config', 0777, true);
        mkdir($this->root . '/framework/bin/lib', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    /**
     * The script is reachable from the plugin root at framework/bin/, and exits
     * zero for a valid plan.
     */
    public function test_the_script_is_present_at_its_relocated_path(): void
    {
        $script = dirname(__DIR__, 2) . '/framework/bin/init-plugin.php';

        $this->assertFileExists($script);
        $this->assertFileExists(dirname($script) . '/lib/Rebrander.php');
        $this->assertFileExists(dirname($script) . '/lib/RebrandPlan.php');
    }

    /**
     * The default `env()` fallbacks in config/app.php are replaced with the
     * rebranded identity — this is the "config fallbacks" half of the hybrid.
     */
    public function test_config_env_fallbacks_are_rebranded(): void
    {
        $this->writeConfig(file_get_contents(
            dirname(__DIR__, 2) . '/config/app.php'
        ));

        $this->runRebrand();

        $config = (string) file_get_contents($this->root . '/config/app.php');

        $this->assertStringContainsString("env('APP_NAME', 'Lead Bridge')", $config);
        $this->assertStringContainsString("env('APP_PREFIX', 'lead_bridge_')", $config);
        $this->assertStringContainsString("env('APP_NAMESPACE', 'LeadBridge')", $config);
        $this->assertStringContainsString("env('CLI_PREFIX', 'leadbridge')", $config);
        $this->assertStringContainsString("env('REST_NAMESPACE', 'lead-bridge/v1')", $config);

        // The environment key names are configuration, not identity, and must
        // survive untouched — a renamed key would silently stop reading `.env`.
        $this->assertStringContainsString("env('APP_ENV', 'production')", $config);
    }

    /**
     * `.env.example` is the template and is always rewritten.
     */
    public function test_env_example_is_rebranded(): void
    {
        $this->writeDotenv('.env.example');

        $this->runRebrand();

        $example = (string) file_get_contents($this->root . '/.env.example');

        $this->assertStringContainsString('APP_NAME="Lead Bridge"', $example);
        $this->assertStringContainsString('APP_PREFIX="lead_bridge_"', $example);
        $this->assertStringContainsString('APP_NAMESPACE="LeadBridge"', $example);
        $this->assertStringContainsString('CLI_PREFIX="leadbridge"', $example);
        $this->assertStringContainsString('REST_NAMESPACE="lead-bridge/v1"', $example);

        // A key the rebrand does not own is left exactly as written.
        $this->assertStringContainsString('APP_ENV=local', $example);
    }

    /**
     * `.env` is rewritten when it exists, so a project that has already set up
     * its environment does not keep naming the old identity — which would win
     * over the config fallbacks at runtime.
     */
    public function test_env_is_rebranded_when_present(): void
    {
        $this->writeDotenv('.env');

        $this->runRebrand();

        $env = (string) file_get_contents($this->root . '/.env');

        $this->assertStringContainsString('APP_NAME="Lead Bridge"', $env);
        $this->assertStringContainsString('APP_PREFIX="lead_bridge_"', $env);
        $this->assertStringContainsString('APP_NAMESPACE="LeadBridge"', $env);
    }

    /**
     * A missing `.env` is not an error: the script is run on a fresh clone,
     * where `.env` does not exist, and must still exit zero.
     */
    public function test_a_missing_env_file_is_not_an_error(): void
    {
        $this->writeDotenv('.env.example');

        $this->assertFileDoesNotExist($this->root . '/.env');

        $this->runRebrand();

        $this->assertFileDoesNotExist($this->root . '/.env');
        $this->assertFileExists($this->root . '/.env.example');
    }

    /**
     * Running twice is a no-op, not a mutation: the second pass finds the
     * rebranded values already in place.
     */
    public function test_a_second_rebrand_does_not_change_the_dotenv_files(): void
    {
        $this->writeDotenv('.env.example');

        $this->runRebrand();

        $afterFirst = (string) file_get_contents($this->root . '/.env.example');

        $this->runRebrand();

        $afterSecond = (string) file_get_contents($this->root . '/.env.example');

        $this->assertSame($afterFirst, $afterSecond);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Run the real script as a subprocess against the scratch tree.
     */
    private function runRebrand(): void
    {
        // The script resolves its root from __DIR__, so running the real copy
        // would rewrite this plugin. Mirror it into the scratch tree first, so
        // INIT_PLUGIN_ROOT lands on the temp directory instead.
        $this->mirrorScriptInto($this->root);

        $localScript = $this->root . '/framework/bin/init-plugin.php';

        $command = sprintf(
            '%s %s --name=%s --slug=%s --vendor=%s --namespace=%s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($localScript),
            escapeshellarg('Lead Bridge'),
            escapeshellarg('lead-bridge'),
            escapeshellarg('acme'),
            escapeshellarg('LeadBridge')
        );

        exec($command, $output, $exitCode);

        $this->assertSame(
            0,
            $exitCode,
            "The rebrander exited {$exitCode}:\n" . implode("\n", $output)
        );
    }

    /**
     * Copy the rebrander into the scratch tree so its `dirname(__DIR__, 2)`
     * root lands on the temp directory rather than the real plugin.
     */
    private function mirrorScriptInto(string $root): void
    {
        $source = dirname(__DIR__, 2) . '/framework/bin';

        foreach (['init-plugin.php', 'lib/Rebrander.php', 'lib/RebrandPlan.php'] as $file) {
            $target = $root . '/framework/bin/' . $file;

            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0777, true);
            }

            copy($source . '/' . $file, $target);
        }
    }

    private function writeConfig(string $contents): void
    {
        file_put_contents($this->root . '/config/app.php', $contents);
    }

    private function writeDotenv(string $name): void
    {
        file_put_contents($this->root . '/' . $name, implode("\n", [
            '# A template that names the starter identity.',
            'APP_NAME="WP Mini Laravel"',
            'APP_ENV=local',
            'APP_DEBUG=true',
            'APP_PREFIX=wp_mini_laravel_',
            'APP_NAMESPACE=App',
            'CLI_PREFIX=wp_mini',
            'REST_NAMESPACE=wp-mini-laravel/v1',
            '',
        ]));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
