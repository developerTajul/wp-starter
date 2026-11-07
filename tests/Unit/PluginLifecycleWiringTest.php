<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

/**
 * The lifecycle hooks, as the entry file registers them.
 *
 * A companion to {@see PluginBootTest}, and separate from it for the same reason
 * that file is one test class: this needs the *real* boot, which is process-wide
 * and one-shot. Everything here runs in its own process.
 *
 * What these tests are for is the wiring rather than the behaviour. The
 * behaviour is covered directly in
 * {@see \Tests\Unit\Lifecycle\PluginLifecycleTest}, against a container this
 * suite controls. What cannot be covered there is whether the entry file
 * registers a callback WordPress will actually fire — and that question has a
 * wrong answer that fails silently, because `register_activation_hook()` refuses
 * a path it cannot basename without raising anything.
 */
final class PluginLifecycleWiringTest extends TestCase
{
    /**
     * The basename WordPress derives from the entry file, and the key both hooks
     * are registered under. Computed through the stub's own `plugin_basename()`
     * rather than hardcoded, so this agrees with what the registration did.
     */
    private function basename(): string
    {
        return plugin_basename(dirname(__DIR__, 2) . '/wp-mini-laravel.php');
    }

    #[RunInSeparateProcess]
    public function test_the_entry_file_registers_the_lifecycle_hooks(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        WpStub::doAction('plugins_loaded');

        $this->assertNotEmpty(
            WpStub::$activationHooks,
            'No activation hook was registered. WordPress would activate the plugin without migrating.'
        );
        $this->assertNotEmpty(
            WpStub::$deactivationHooks,
            'No deactivation hook was registered, so rewrite rules would be left behind.'
        );
    }

    /**
     * The registration must be *fireable*. `register_activation_hook()` computes
     * `plugin_basename( $file )` and returns silently when the result contains no
     * directory separator — so an entry file that passed `__DIR__ . '/wp-mini-laravel.php'`
     * would look correct and register nothing. The stub models that rule, and
     * this asserts the registered key is one a real WordPress would look up.
     */
    #[RunInSeparateProcess]
    public function test_the_registered_hooks_are_keyed_by_the_plugin_basename(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        WpStub::doAction('plugins_loaded');

        $basename = $this->basename();

        $this->assertArrayHasKey($basename, WpStub::$activationHooks);
        $this->assertArrayHasKey($basename, WpStub::$deactivationHooks);
        $this->assertStringContainsString(
            '/',
            $basename,
            'The basename has no separator, so WordPress would refuse to register the hook at all.'
        );
    }

    /**
     * Firing activation must migrate. The end-to-end assertion for requirement 1:
     * the hook is registered, it resolves a migrator, and it runs it.
     */
    #[RunInSeparateProcess]
    public function test_firing_the_activation_hook_boots_and_migrates(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        WpStub::doAction('plugins_loaded');

        $db = new \Tests\Support\FakeWpdb();
        $GLOBALS['wpdb'] = $db;

        // The real project migrations run here, against the fake — which is the
        // point: this is the shipped `database/migrations` directory, not a
        // fixture. Anything in it that cannot run at activation time fails this.
        WpStub::activatePlugin($this->basename());

        $this->assertTrue(
            $db->tableExists('wp_plugin_migrations'),
            'Activation ran no migrations at all.'
        );
        $this->assertNotEmpty(
            $db->rows('wp_plugin_migrations'),
            'Activation created the ledger but recorded nothing in it.'
        );

        unset($GLOBALS['wpdb']);
    }

    /**
     * Deactivation must not drop anything, whatever the uninstall option says.
     *
     * The option is set to `true` here deliberately: the whole requirement is
     * that the *uninstall* setting is irrelevant to deactivation, and a test that
     * leaves it at its default cannot tell a correct implementation from one that
     * reads the wrong option.
     */
    #[RunInSeparateProcess]
    public function test_firing_the_deactivation_hook_drops_nothing(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        WpStub::doAction('plugins_loaded');

        $db = new \Tests\Support\FakeWpdb();
        $GLOBALS['wpdb'] = $db;
        $rewrite = new \WP_Rewrite();
        $GLOBALS['wp_rewrite'] = $rewrite;

        WpStub::activatePlugin($this->basename());

        $tablesBefore = $db->tableExists('wp_plugin_migrations');
        $this->assertTrue($tablesBefore, 'Precondition: activation built no tables.');

        // The option that gates the *uninstall* wipe, turned on. Deactivation
        // must ignore it entirely.
        \App\Lifecycle\PluginLifecycle::setDeleteDataOnUninstall(true);

        WpStub::deactivatePlugin($this->basename());

        $this->assertTrue(
            $db->tableExists('wp_plugin_migrations'),
            'Deactivation dropped the migration ledger.'
        );
        $this->assertNotEmpty(
            $db->rows('wp_plugin_migrations'),
            'Deactivation emptied the migration ledger.'
        );
        $this->assertSame([false], $rewrite->flushes, 'Deactivation should have flushed rewrite rules.');

        unset($GLOBALS['wpdb'], $GLOBALS['wp_rewrite']);
    }

    /**
     * `register_uninstall_hook()` must **not** be used.
     *
     * It stores its callback in the `uninstall_plugins` option, so the
     * registration lives in the database being deleted and leaves a stale entry
     * behind naming a callback the autoloader can no longer find. `uninstall.php`
     * stores nothing, which is why it is the form this plugin uses.
     */
    #[RunInSeparateProcess]
    public function test_the_entry_file_does_not_register_an_uninstall_hook(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        WpStub::doAction('plugins_loaded');

        $this->assertSame(
            [],
            WpStub::$uninstallHooks,
            'register_uninstall_hook() was used, which stores its callback in the database being deleted.'
        );
    }

    /**
     * The boot must still be deferred to `plugins_loaded`, and firing that action
     * must still boot — the lifecycle registration was added inside the same
     * callback, and a mistake there would be silent.
     */
    #[RunInSeparateProcess]
    public function test_plugins_loaded_still_boots_the_container(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        $this->assertFalse(app()->isBooted(), 'The container booted during require, not on plugins_loaded.');

        WpStub::doAction('plugins_loaded');

        $this->assertTrue(app()->isBooted());
    }

    /**
     * The ROUTES registration is unaffected — the lifecycle work went into the
     * same callback, and it would be easy to drop the boot or reorder it past the
     * routes provider without noticing here.
     */
    #[RunInSeparateProcess]
    public function test_the_rest_routes_are_still_registered(): void
    {
        WpStub::reset();

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        WpStub::doAction('plugins_loaded');
        WpStub::doAction('rest_api_init');

        $namespace = (string) config('app.rest_namespace');

        $this->assertArrayHasKey($namespace, WpStub::$registeredRoutes);
    }
}
