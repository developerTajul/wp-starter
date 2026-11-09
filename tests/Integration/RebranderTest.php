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
     *
     * `APP_NAMESPACE` keeps the `App` layer and gains the root in front of it:
     * the rebrand moves the namespace root and preserves the architecture's
     * logical layers, so the value is `LeadBridge\App`, never a bare
     * `LeadBridge`.
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
        $this->assertStringContainsString("env('APP_NAMESPACE', 'LeadBridge\\App')", $config);
        $this->assertStringContainsString("env('CLI_PREFIX', 'leadbridge')", $config);
        $this->assertStringContainsString("env('REST_NAMESPACE', 'lead-bridge/v1')", $config);

        // The environment key names are configuration, not identity, and must
        // survive untouched — a renamed key would silently stop reading `.env`.
        $this->assertStringContainsString("env('APP_ENV', 'production')", $config);
    }

    /**
     * `.env.example` is the template and is always rewritten.
     *
     * `APP_NAMESPACE` is single-quoted on purpose: phpdotenv reads a backslash in
     * a double-quoted value as an escape sequence and *fails to parse* `\A`,
     * which `safeLoad()` does not swallow. So the assertion pins the quote style,
     * not just the value.
     */
    public function test_env_example_is_rebranded(): void
    {
        $this->writeDotenv('.env.example');

        $this->runRebrand();

        $example = (string) file_get_contents($this->root . '/.env.example');

        $this->assertStringContainsString('APP_NAME="Lead Bridge"', $example);
        $this->assertStringContainsString('APP_PREFIX="lead_bridge_"', $example);
        $this->assertStringContainsString("APP_NAMESPACE='LeadBridge\\App'", $example);
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
        $this->assertStringContainsString("APP_NAMESPACE='LeadBridge\\App'", $env);
    }

    /**
     * Composer's PSR-4 keys keep the layer names and gain the root.
     *
     * This is the mapping that decides whether the rebranded tree autoloads at
     * all: `LeadBridge\App\` must point at `app/` and `LeadBridge\Core\` at
     * `core/`. A mapping that dropped the layers would leave the files declaring
     * `LeadBridge\App\...` unfindable, and `composer dump-autoload` would report
     * success anyway.
     */
    public function test_composer_psr4_maps_prefix_the_layer_names(): void
    {
        $this->writeJson('composer.json', [
            'name' => 'devlinkstudios/wp-mini-laravel',
            'autoload' => [
                'psr-4' => [
                    'App\\' => 'app/',
                    'Core\\' => 'core/',
                ],
            ],
            'autoload-dev' => [
                'psr-4' => [
                    'Tests\\' => 'tests/',
                ],
            ],
        ]);

        $this->runRebrand();

        $data = json_decode((string) file_get_contents($this->root . '/composer.json'), true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('LeadBridge\\App\\', $data['autoload']['psr-4']);
        $this->assertArrayHasKey('LeadBridge\\Core\\', $data['autoload']['psr-4']);
        $this->assertSame('app/', $data['autoload']['psr-4']['LeadBridge\\App\\']);
        $this->assertSame('core/', $data['autoload']['psr-4']['LeadBridge\\Core\\']);

        // The layer names are preserved rather than replaced.
        $this->assertArrayNotHasKey('LeadBridge\\', $data['autoload']['psr-4']);
        $this->assertArrayNotHasKey('App\\', $data['autoload']['psr-4']);

        // `Tests\` is a path root, not a project layer, and does not move.
        $this->assertArrayHasKey('Tests\\', $data['autoload-dev']['psr-4']);
    }

    /**
     * A namespace declaration is prefixed, not overwritten.
     */
    public function test_php_namespaces_are_prefixed(): void
    {
        $this->writeFile('app/Http/Controllers/LeadController.php', <<<'PHP'
        <?php
        namespace App\Http\Controllers;

        use App\Http\Resources\LeadResource;
        use Core\Note\Application\NoteService;
        use Framework\Http\Responses\JsonResponse;

        final class LeadController
        {
        }
        PHP);

        $this->runRebrand();

        $php = (string) file_get_contents($this->root . '/app/Http/Controllers/LeadController.php');

        $this->assertStringContainsString('namespace LeadBridge\\App\\Http\\Controllers;', $php);
        $this->assertStringContainsString('use LeadBridge\\App\\Http\\Resources\\LeadResource;', $php);
        $this->assertStringContainsString('use LeadBridge\\Core\\Note\\Application\\NoteService;', $php);

        // Framework is the library's own namespace and never moves.
        $this->assertStringContainsString('use Framework\\Http\\Responses\\JsonResponse;', $php);
    }

    /**
     * A layer token that is a **path segment** under `Tests\` must not move,
     * while the import on the next line must.
     *
     * `tests/Integration/App/Repositories/` declares `Tests\Integration\App\...`
     * and imports `App\Repositories\...` in the same file. Both spell `App\`;
     * only the second one is the project's namespace. Rewriting the first breaks
     * PSR-4 resolution for every file under that directory, and the failure
     * reads as a typo in the test rather than a rebrand defect.
     */
    public function test_test_suite_path_segments_are_not_rewritten(): void
    {
        $this->writeFile('tests/Integration/App/Repositories/WpNoteRepositoryTest.php', <<<'PHP'
        <?php
        namespace Tests\Integration\App\Repositories;

        use App\Repositories\WpNoteRepository;

        final class WpNoteRepositoryTest
        {
        }
        PHP);

        $this->runRebrand();

        $php = (string) file_get_contents(
            $this->root . '/tests/Integration/App/Repositories/WpNoteRepositoryTest.php'
        );

        $this->assertStringContainsString('namespace Tests\\Integration\\App\\Repositories;', $php);

        // The declaration is the path segment and stays as written...
        $this->assertStringNotContainsString('namespace Tests\\Integration\\LeadBridge', $php);

        // ...while the import two lines down is the project's namespace and moves.
        $this->assertStringContainsString('use LeadBridge\\App\\Repositories\\WpNoteRepository;', $php);
    }

    /**
     * A second run must not double the prefix.
     *
     * The rules now emit namespaces that contain the token they match — `App`
     * inside `LeadBridge\App`, `Core` inside `LeadBridge\Core` — so the guards
     * that stop a rule re-firing on its own output are load-bearing rather than
     * defensive.
     */
    public function test_a_second_rebrand_does_not_double_prefix_namespaces(): void
    {
        $this->writeFile('app/Kernel.php', <<<'PHP'
        <?php
        namespace App;

        use Core\Shared\Application\DTOs\Result;

        final class Kernel
        {
        }
        PHP);

        $this->runRebrand();
        $afterFirst = (string) file_get_contents($this->root . '/app/Kernel.php');

        $this->runRebrand();
        $afterSecond = (string) file_get_contents($this->root . '/app/Kernel.php');

        $this->assertStringContainsString('namespace LeadBridge\\App;', $afterFirst);
        $this->assertSame($afterFirst, $afterSecond);
        $this->assertStringNotContainsString('LeadBridge\\LeadBridge', $afterSecond);
    }

    /**
     * This script's own test file describes the *starter's* identity on purpose:
     * its fixtures write `App\` and `Core\` into a scratch tree and assert what
     * the rebrand did to them. Rewriting it would move the fixture and the
     * assertion independently, so the rebranded project would ship a red suite
     * whose failure pointed at the rebrander rather than at the fixture.
     *
     * Nothing in the file needs rebranding anyway — it is `Tests\` and PHPUnit.
     */
    public function test_the_rebranders_own_test_file_is_left_alone(): void
    {
        $source = <<<'PHP'
        <?php
        namespace Tests\Integration;

        final class RebranderTest
        {
            /** @var array<string, string> */
            private array $psr4 = ['App\\' => 'app/', 'Core\\' => 'core/'];
        }
        PHP;

        $this->writeFile('tests/Integration/RebranderTest.php', $source);

        $this->runRebrand();

        $this->assertSame(
            $source,
            (string) file_get_contents($this->root . '/tests/Integration/RebranderTest.php')
        );
    }

    /**
     * `--namespace` is the *root*, and the flag is refused when it already ends
     * with a layer.
     *
     * The rules append `App` / `Core` to the root, so `--namespace=LeadBridge\App`
     * would silently produce `LeadBridge\App\App\...` — namespaces no file
     * declares, which Composer then cannot resolve, in exactly the quiet way a
     * wrong PSR-4 map fails.
     */
    public function test_a_namespace_root_that_already_names_a_layer_is_rejected(): void
    {
        [$exitCode, $output] = $this->executeRebrand('LeadBridge\\App');

        $this->assertSame(1, $exitCode, implode("\n", $output));
        $this->assertStringContainsString(
            'already ends with a layer name',
            implode("\n", $output)
        );
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
     * `mini` — the extension-less CLI entry point — is rewritten like any source
     * file.
     *
     * It carries `MINI_DEFAULT_PREFIX = 'wp_mini'`, which is one of the value's
     * three homes alongside `config/app.php` and `.env`. The walk selects files
     * by *extension*, and `mini` has none, so it was skipped silently: the
     * rebranded project shipped an entry point forwarding the old namespace to
     * WP-CLI, with the report showing no sign that anything had been missed.
     *
     * Pinned here rather than in the shared fixture list, because the failure is
     * an omission rather than a wrong rewrite — a test that only checked the
     * files it knew about could not have caught it.
     */
    public function test_the_extension_less_cli_entry_point_is_rewritten(): void
    {
        $this->writeFile('mini', <<<'PHP'
        #!/usr/bin/env php
        <?php
        const MINI_DEFAULT_PREFIX = 'wp_mini';
        PHP);

        $this->runRebrand();

        $contents = (string) file_get_contents($this->root . '/mini');

        $this->assertStringContainsString("MINI_DEFAULT_PREFIX = 'leadbridge'", $contents);
        $this->assertStringNotContainsString('wp_mini', $contents);
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
    private function runRebrand(string $namespace = 'LeadBridge'): void
    {
        [$exitCode, $output] = $this->executeRebrand($namespace);

        $this->assertSame(
            0,
            $exitCode,
            "The rebrander exited {$exitCode}:\n" . implode("\n", $output)
        );
    }

    /**
     * The subprocess exit code and output, without asserting either.
     *
     * Split out for the cases that assert a *refusal*, where the interesting
     * thing is that the script stopped before writing anything.
     *
     * @return array{int, list<string>}
     */
    private function executeRebrand(string $namespace): array
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
            escapeshellarg($namespace)
        );

        exec($command, $output, $exitCode);

        return [$exitCode, $output];
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

    /**
     * Write a file at a root-relative path, creating the directories it needs.
     */
    private function writeFile(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $contents);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $relative, array $data): void
    {
        $this->writeFile(
            $relative,
            (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
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
