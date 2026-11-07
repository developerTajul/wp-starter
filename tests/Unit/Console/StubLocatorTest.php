<?php
declare(strict_types=1);

namespace Tests\Unit\Console;

use Framework\Console\StubLocator;
use Framework\Console\StubNotFoundException;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

/**
 * Stub resolution, which is what makes `make:*` work from `vendor/`.
 *
 * ## Why this needs its own tests
 *
 * The bug this class fixes is invisible in a project checkout: the stubs are
 * right there, `base_path()` finds them, and every generator works. It only
 * appears once the framework is installed as a Composer package, where
 * `base_path()` names the consuming plugin and the framework's own files are
 * under `vendor/` — a context no test here runs in, and which no amount of
 * running the suite against this repo would reveal.
 *
 * So the two locations are simulated directly: a workspace that has no `stubs/`
 * directory stands in for a Composer install, and one that does stands in for a
 * project with overrides.
 */
final class StubLocatorTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->workspace = sys_get_temp_dir() . '/wpml-stubs-' . bin2hex(random_bytes(6));
        mkdir($this->workspace, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
    }

    /**
     * The case the class exists for: a project with no stubs of its own still
     * gets a working resolution, because the framework ships the defaults.
     */
    public function test_it_falls_back_to_the_framework_directory(): void
    {
        $locator = new StubLocator($this->workspace);

        $resolved = $locator->resolve('migration.stub');

        $this->assertFileExists($resolved);
        $this->assertStringContainsString('migration.stub', $resolved);
        $this->assertSame('framework', $locator->source('migration.stub'));
    }

    /**
     * Every stub a generator asks for must be shipped, or the fallback is only
     * theoretically there — one missing default is one command that still fails
     * on a Composer install.
     */
    public function test_every_generator_stub_is_shipped_with_the_framework(): void
    {
        $locator = new StubLocator($this->workspace);

        foreach ([
            'controller.stub',
            'provider.stub',
            'routes.stub',
            'command.stub',
            // `migration.stub` is the no-table shape; the other two are selected
            // by `make:migration --create` and `--table`. A missing variant fails
            // only when a developer happens to pass that flag, which is exactly
            // the kind of gap a hand-checked list leaves behind.
            'migration.stub',
            'migration.create.stub',
            'migration.table.stub',
        ] as $name) {
            $this->assertTrue(
                $locator->has($name),
                "The framework must ship [{$name}]; without it, the corresponding make:* command "
                . 'fails on any install where the framework is not the project itself.'
            );
        }
    }

    /**
     * The framework's fallback must live inside the framework package, not at the
     * project root. Resolved relative to the class, so the property holds however
     * the package was installed.
     */
    public function test_the_fallback_directory_is_inside_the_framework_package(): void
    {
        $frameworkDir = (new \ReflectionClass(StubLocator::class))->getFileName();

        $this->assertIsString($frameworkDir);
        $this->assertStringStartsWith(
            str_replace('\\', '/', dirname($frameworkDir)),
            str_replace('\\', '/', StubLocator::frameworkDirectory()),
            'The default stubs must sit beside the framework source, or a vendor/ install finds none.'
        );
    }

    /**
     * A project stub wins, which is what makes the defaults overridable without
     * patching a vendored file that the next `composer update` would discard.
     */
    public function test_a_project_stub_overrides_the_framework_default(): void
    {
        mkdir($this->workspace . '/stubs', 0755, true);
        file_put_contents($this->workspace . '/stubs/controller.stub', 'project version');

        $locator = new StubLocator($this->workspace);

        $this->assertSame('project', $locator->source('controller.stub'));
        $this->assertSame('project version', file_get_contents($locator->resolve('controller.stub')));
    }

    /**
     * The override is per stub, not per directory. A project that customizes one
     * stub must not lose the framework's defaults for the other four — which is
     * what a "does the directory exist" check would do.
     */
    public function test_overriding_one_stub_does_not_shadow_the_others(): void
    {
        mkdir($this->workspace . '/stubs', 0755, true);
        file_put_contents($this->workspace . '/stubs/controller.stub', 'project version');

        $locator = new StubLocator($this->workspace);

        $this->assertSame('project', $locator->source('controller.stub'));
        $this->assertSame('framework', $locator->source('migration.stub'));
    }

    /**
     * A missing stub is a packaging bug. The message has to name both places that
     * were searched, or the reader is sent to create a file the framework already
     * ships.
     */
    public function test_a_missing_stub_reports_both_paths_searched(): void
    {
        $locator = new StubLocator($this->workspace);

        try {
            $locator->resolve('absent.stub');
            $this->fail('Resolving a stub that exists nowhere must throw.');
        } catch (StubNotFoundException $e) {
            $this->assertStringContainsString('absent.stub', $e->getMessage());
            $this->assertStringContainsString(str_replace('\\', '/', $this->workspace), str_replace('\\', '/', $e->getMessage()));
            $this->assertStringContainsString(
                str_replace('\\', '/', StubLocator::frameworkDirectory()),
                str_replace('\\', '/', $e->getMessage())
            );
        }
    }

    /**
     * `has()` must agree with `resolve()`. A predicate that says yes where the
     * resolver throws is worse than no predicate, because the caller branches on
     * it and then fails anyway.
     */
    public function test_has_agrees_with_resolve(): void
    {
        $locator = new StubLocator($this->workspace);

        $this->assertTrue($locator->has('provider.stub'));
        $this->assertFalse($locator->has('absent.stub'));
        $this->assertNull($locator->source('absent.stub'));
    }

    /**
     * A directory named like a stub must not count as one. `file_exists` would
     * accept it and `file_get_contents` would then fail with "failed to open
     * stream", naming the symptom rather than the problem.
     */
    public function test_a_directory_is_not_mistaken_for_a_stub(): void
    {
        mkdir($this->workspace . '/stubs/thing.stub', 0755, true);

        $locator = new StubLocator($this->workspace);

        $this->assertFalse($locator->has('thing.stub'));
        $this->assertSame('framework', $locator->source('thing.stub') ?? 'framework');
    }

    /**
     * The candidate order is a contract: project first. Returned rather than left
     * implicit so a caller reporting "where did this come from" cannot guess it
     * backwards.
     */
    public function test_candidates_are_listed_project_first(): void
    {
        $candidates = (new StubLocator($this->workspace))->candidates('thing.stub');

        $this->assertCount(2, $candidates);
        $this->assertStringContainsString($this->workspace, $candidates[0]);
        $this->assertStringContainsString(StubLocator::frameworkDirectory(), $candidates[1]);
    }

    /**
     * A base path with a trailing separator is the common shape when it comes
     * from a config value, and must not produce a doubled separator that misses
     * the file on a strict filesystem.
     */
    public function test_a_trailing_separator_on_the_base_path_is_tolerated(): void
    {
        mkdir($this->workspace . '/stubs', 0755, true);
        file_put_contents($this->workspace . '/stubs/thing.stub', 'x');

        $locator = new StubLocator($this->workspace . '/');

        $this->assertSame('project', $locator->source('thing.stub'));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }

        rmdir($path);
    }
}
