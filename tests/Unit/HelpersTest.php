<?php
declare(strict_types=1);

namespace Tests\Unit;

use DevLinkStudios\WPServiceContainer\Container;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Covers the path and config helpers in framework/Support/helpers.php.
 *
 * These helpers read container bindings, and app() holds that container in a
 * function-level static that lives for the whole PHP process. Any test that
 * depends on the container starting out empty therefore has to run in its own
 * process, or it would leak bindings into every test after it.
 */
final class HelpersTest extends TestCase
{
    /**
     * base_path() must refuse to guess. A wrong root does not fail loudly at
     * the point of the mistake; it surfaces much later as a stub written to,
     * or a config read from, the wrong directory. Throwing here turns that
     * into an immediate, traceable failure.
     *
     * The guard is exercised against a container that is deliberately left
     * unbound — not against whatever state the process happens to be in, which
     * would only prove that nothing had booted yet.
     */
    #[RunInSeparateProcess]
    public function test_base_path_throws_when_path_base_is_not_bound(): void
    {
        // Touch app() first so the container exists, then assert the specific
        // precondition the test needs rather than assuming it.
        $container = app();
        $this->assertInstanceOf(Container::class, $container);
        $container->forget('path.base');
        $this->assertFalse($container->bound('path.base'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Base path is not bound in the container.');

        base_path();
    }

    /**
     * A bound base path is joined with the platform separator, and a leading
     * separator on the relative part must not produce a doubled separator —
     * callers routinely pass '/config' as readily as 'config'.
     */
    #[RunInSeparateProcess]
    public function test_base_path_joins_a_relative_path(): void
    {
        app()->instance('path.base', '/srv/plugin');

        $this->assertSame('/srv/plugin', base_path());
        $this->assertSame('/srv/plugin/config', base_path('config'));
        $this->assertSame('/srv/plugin/config', base_path('/config'));
    }

    /**
     * A trailing separator on the bound root must not survive into joined
     * paths — otherwise every generated path carries a stray slash, which is
     * exactly what makes `make:provider` output awkward to copy and paste.
     */
    #[RunInSeparateProcess]
    public function test_base_path_normalizes_a_trailing_separator_on_the_root(): void
    {
        app()->instance('path.base', '/srv/plugin/');

        $this->assertSame('/srv/plugin', base_path());
        $this->assertSame('/srv/plugin/app', base_path('app'));
    }

    #[RunInSeparateProcess]
    public function test_app_path_and_config_path_are_relative_to_the_base_path(): void
    {
        app()->instance('path.base', '/srv/plugin');

        $this->assertSame('/srv/plugin/app', app_path());
        $this->assertSame('/srv/plugin/app/Http', app_path('Http'));
        $this->assertSame('/srv/plugin/config', config_path());
        $this->assertSame('/srv/plugin/config/app.php', config_path('app.php'));
    }

    /**
     * The message is the only thing a developer sees when this fires during a
     * boot failure, so pin it against accidental drift into something generic.
     *
     * Note it deliberately does NOT assert the binding key 'path.base': the
     * message names the concept ("Base path"), not the internal key. Asserting
     * the key here would couple the test to an implementation detail.
     */
    #[RunInSeparateProcess]
    public function test_base_path_exception_message_explains_the_missing_binding(): void
    {
        app()->forget('path.base');

        try {
            base_path();
            $this->fail('Expected a RuntimeException from base_path().');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not bound', $e->getMessage());
            $this->assertStringContainsString('container', $e->getMessage());
        }
    }

    /**
     * app() is the container accessor for the whole process. Resolving it twice
     * must hand back the same instance, or bindings registered by one provider
     * would be invisible to code resolving through the other.
     */
    #[RunInSeparateProcess]
    public function test_app_returns_a_shared_container_instance(): void
    {
        $this->assertSame(app(), app());
    }

    /**
     * Providers type-hint the container interface, and the container refuses to
     * guess at an unbound interface — so the self-binding has to be present for
     * any provider to be constructible at all.
     */
    #[RunInSeparateProcess]
    public function test_app_container_is_bound_to_its_own_interface_and_class(): void
    {
        $container = app();

        $this->assertSame($container, app(\DevLinkStudios\WPServiceContainer\Container::class));
        $this->assertSame(
            $container,
            app(\DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface::class)
        );
    }
}
