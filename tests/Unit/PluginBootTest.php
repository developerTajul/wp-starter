<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

/**
 * Boots the actual entry file, under the WordPress function stubs.
 *
 * This is the only test that covers wp-mini-laravel.php, and it is the one that
 * would have caught the boot-lifecycle bugs Phase 1 fixed: the entry file is the
 * sole place that binds `path.base`, and every path helper depends on it.
 *
 * Runs in a separate process because the entry file defines constants and
 * installs a container that are process-global by design — booting it twice in
 * one process would be testing the idempotency guard, not the boot.
 */
final class PluginBootTest extends TestCase
{
    #[RunInSeparateProcess]
    public function test_the_entry_file_boots_and_binds_the_base_path(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        $this->assertTrue(defined('WP_MINI_LARAVEL_BOOTED'));
        $this->assertTrue(app()->bound('path.base'));
    }

    /**
     * The base path must be the plugin directory — the entry file is the only
     * code that knows where the installation lives, and a wrong root would send
     * make:* output and config reads to the wrong place.
     */
    #[RunInSeparateProcess]
    public function test_the_bound_base_path_is_the_plugin_directory(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        $this->assertSame(
            str_replace('\\', '/', dirname(__DIR__, 2)),
            str_replace('\\', '/', app('path.base'))
        );
    }

    /**
     * Path helpers resolve through the binding, so booting the entry file is
     * what makes them usable at all.
     */
    #[RunInSeparateProcess]
    public function test_path_helpers_resolve_after_boot(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        $this->assertFileExists(base_path('composer.json'));
        $this->assertSame(base_path('app'), app_path());
        $this->assertSame(base_path('config'), config_path());
    }

    /**
     * Configuration is loaded by the entry file, so the project's own values
     * must be readable by the time it returns.
     */
    #[RunInSeparateProcess]
    public function test_configuration_is_loaded_by_the_entry_file(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        $this->assertSame('App', config('app.namespace'));
        $this->assertIsArray(config('app.providers'));
    }

    /**
     * boot() is deferred to `plugins_loaded` so providers can touch WordPress
     * hooks that are not available at plugin-load time. The hook must therefore
     * be registered — but the providers must NOT have booted yet.
     */
    #[RunInSeparateProcess]
    public function test_provider_boot_is_deferred_to_plugins_loaded(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        $this->assertNotEmpty(
            WpStub::actionsFor('plugins_loaded'),
            'The entry file must defer boot() to the plugins_loaded action.'
        );
    }

    /**
     * Every provider in config/app.php must be registered, and registering is
     * what makes the router resolvable — the whole REST layer hangs off it.
     */
    #[RunInSeparateProcess]
    public function test_the_configured_providers_are_registered(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        $providers = config('app.providers', []);

        $this->assertContains(\App\Providers\AppServiceProvider::class, $providers);
        $this->assertContains(\App\Providers\RouteServiceProvider::class, $providers);
    }

    /**
     * The routes provider registers the router as a shared binding. Two
     * resolutions must return one instance, or routes registered through one
     * would be missing from the other.
     */
    #[RunInSeparateProcess]
    public function test_the_router_is_a_shared_binding(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        $container = app();
        $container->boot();

        $this->assertSame(
            $container->resolve(\Framework\Routing\Router::class),
            $container->resolve(\Framework\Routing\Router::class)
        );
    }

    /**
     * The routes file ships with the plugin and must define the ping endpoint —
     * an unauthenticated endpoint that proves the routing layer is wired up
     * without depending on any other part of the application.
     */
    #[RunInSeparateProcess]
    public function test_the_shipped_routes_file_registers_the_ping_endpoint(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        $container = app();
        $container->boot();

        // Firing rest_api_init is what the provider hooks into; the stub calls
        // the registered callback exactly as WordPress would.
        WpStub::doAction('rest_api_init');

        $namespace = (string) config('app.rest_namespace');
        $this->assertArrayHasKey($namespace, WpStub::$registeredRoutes);

        $paths = array_column(WpStub::$registeredRoutes[$namespace], 'route');
        $this->assertContains('ping', $paths);
    }

    /**
     * WordPress can fire rest_api_init more than once in a process. The second
     * firing must not re-register the routes and throw out of the action.
     */
    #[RunInSeparateProcess]
    public function test_firing_rest_api_init_twice_does_not_throw(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        app()->boot();

        WpStub::doAction('rest_api_init');
        WpStub::doAction('rest_api_init');

        $namespace = (string) config('app.rest_namespace');

        $this->assertCount(1, WpStub::$registeredRoutes[$namespace]);
    }

    /**
     * Every registered route must carry a permission callback: WordPress errors
     * on a missing one, and a route that ends up public by omission is a
     * security bug rather than a convenience.
     */
    #[RunInSeparateProcess]
    public function test_every_registered_route_has_a_permission_callback(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        $container = app();
        $container->boot();
        WpStub::doAction('rest_api_init');

        $namespace = (string) config('app.rest_namespace');

        foreach (WpStub::$registeredRoutes[$namespace] as $registration) {
            $this->assertArrayHasKey('permission_callback', $registration);
            $this->assertIsCallable($registration['permission_callback']);
        }
    }
}
