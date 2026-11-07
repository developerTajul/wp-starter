<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;
use Tests\Support\WpStub;

/**
 * Activation as WordPress actually performs it — not as the plugin would like.
 *
 * ## Why this file exists
 *
 * {@see PluginLifecycleWiringTest} fires `plugins_loaded` and *then* activates,
 * which is the one ordering WordPress never produces during a real activation.
 * Every test there passed while the plugin activated cleanly, reported success,
 * and created no tables at all.
 *
 * The real order, read off `wp-admin/includes/plugin.php` and `wp-settings.php`:
 *
 *  1. `wp-settings.php` includes the *active* plugins and fires `plugins_loaded`
 *     (line 630). A plugin being activated is **not** in that list yet, so it is
 *     not included and none of its callbacks are registered when the action
 *     fires. That action never fires again in the request.
 *  2. The admin posts to `plugins.php`, which calls `activate_plugin()`.
 *  3. `activate_plugin()` calls `plugin_sandbox_scrape()`, which does
 *     `include_once WP_PLUGIN_DIR . '/' . $plugin` — **this** is the first time
 *     the entry file runs.
 *  4. Only then does core fire `do_action( "activate_{$plugin}", $network_wide )`.
 *
 * So anything the plugin registers inside a `plugins_loaded` callback does not
 * exist by the time step 4 arrives. The registration has to happen at include
 * time, which is step 3.
 *
 * ## Why no fake database could catch this
 *
 * The failure is not in the migration engine; it is in *whether the engine is
 * ever reached*. A test that calls `activate()` directly, or fires
 * `plugins_loaded` first, exercises a path where the hook is registered and
 * therefore cannot observe the bug. These tests instead reproduce steps 1–4 in
 * order, with nothing registered beforehand.
 */
final class ActivationBootOrderTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    /**
     * The whole bug, in one test: a cold activation must create the tables.
     *
     * "Cold" is the point — `plugins_loaded` is fired *before* the entry file is
     * included, exactly as `wp-settings.php` does for a plugin that is not yet
     * active, and never again afterwards.
     */
    #[RunInSeparateProcess]
    public function test_a_cold_activation_creates_the_plugin_tables(): void
    {
        WpStub::reset();

        // Step 1: `plugins_loaded` fires with the plugin not yet included.
        WpStub::doAction('plugins_loaded');

        // Step 3: the sandbox scrape includes the entry file.
        $db = $this->installDatabase();

        // Step 4: core fires the activation hook.
        WpStub::activatePlugin($this->basename());

        $this->assertTrue(
            $db->tableExists('wp_leads'),
            'Activation created no tables. The activation hook was never registered, '
            . 'because it is registered inside a plugins_loaded callback that had already fired.'
        );
    }

    /**
     * The same run must also record what it applied, or the next activation
     * would try again and the ledger would be a lie.
     */
    #[RunInSeparateProcess]
    public function test_a_cold_activation_records_what_it_applied(): void
    {
        WpStub::reset();

        WpStub::doAction('plugins_loaded');

        $db = $this->installDatabase();

        WpStub::activatePlugin($this->basename());

        $this->assertTrue($db->tableExists('wp_plugin_migrations'));
        $this->assertNotEmpty(
            $db->rows('wp_plugin_migrations'),
            'The ledger is empty, so a second activation would re-run every migration.'
        );
    }

    /**
     * The registered key must be the plugin's real basename, which is what core
     * looks up when it fires `activate_{$plugin}`.
     *
     * Registered but unkeyed correctly is indistinguishable from unregistered at
     * runtime, which is why this is asserted separately from the tables.
     */
    #[RunInSeparateProcess]
    public function test_the_activation_hook_is_registered_at_include_time(): void
    {
        WpStub::reset();

        WpStub::doAction('plugins_loaded');

        $this->assertSame([], WpStub::$activationHooks, 'Precondition: nothing should be registered yet.');

        $this->installDatabase();

        // Asserted *without* firing `plugins_loaded` again — the registration
        // must have happened by the time the include returned.
        $this->assertArrayHasKey(
            $this->basename(),
            WpStub::$activationHooks,
            'The activation hook was not registered by the time the entry file finished loading.'
        );
    }

    /**
     * Deactivation is registered the same way and must survive the same ordering:
     * WordPress fires `deactivate_{plugin}` from `deactivate_plugins()`, in a
     * request where `plugins_loaded` has already been and gone.
     */
    #[RunInSeparateProcess]
    public function test_the_deactivation_hook_is_registered_at_include_time(): void
    {
        WpStub::reset();

        WpStub::doAction('plugins_loaded');

        $this->installDatabase();

        $this->assertArrayHasKey(
            $this->basename(),
            WpStub::$deactivationHooks,
            'The deactivation hook was not registered by the time the entry file finished loading.'
        );
    }

    /**
     * Registering at include time must not boot the container at include time.
     *
     * That is the property the original `plugins_loaded` placement was reaching
     * for, and it still has to hold: the boot is deferred, the *registration* is
     * not. A fix that simply moved everything to include time would boot the
     * container for every request that loads the plugin, which is most of them.
     */
    #[RunInSeparateProcess]
    public function test_registering_at_include_time_does_not_boot_the_container(): void
    {
        WpStub::reset();

        WpStub::doAction('plugins_loaded');
        $this->installDatabase();

        $this->assertFalse(
            app()->isBooted(),
            'The container booted during the include, before plugins_loaded fired for an active plugin.'
        );
    }

    /**
     * An already-active plugin still boots on `plugins_loaded`. The normal
     * request — the plugin active, `wp-settings.php` including it at line 582 —
     * must be unaffected by whatever registration the fix adds at include time.
     */
    #[RunInSeparateProcess]
    public function test_an_active_plugin_still_boots_on_plugins_loaded(): void
    {
        WpStub::reset();

        $db = $this->installDatabase();

        $this->assertFalse(app()->isBooted(), 'The include booted the container.');

        WpStub::doAction('plugins_loaded');

        $this->assertTrue(app()->isBooted(), 'plugins_loaded did not boot the container.');
    }

    /**
     * Activation on a site where the plugin is *already* active boots the
     * container exactly once and does not double-register the hooks.
     *
     * `activate_plugin()` can run for a plugin that is being reactivated, and the
     * entry file's `WP_MINI_LARAVEL_BOOTED` guard makes its top level run once —
     * but `plugins_loaded` may also be in play in the same request. The
     * registration has to be idempotent either way.
     */
    #[RunInSeparateProcess]
    public function test_registration_happens_once_however_many_times_the_request_loads_the_plugin(): void
    {
        WpStub::reset();

        $this->installDatabase();

        // The active-plugin path: `wp-settings.php` includes it, then fires the
        // action. Then the request goes on to activate it again.
        WpStub::doAction('plugins_loaded');
        WpStub::doAction('plugins_loaded');

        $this->assertCount(
            1,
            WpStub::$activationHooks,
            'The activation hook was registered more than once.'
        );
    }

    // -------------------------------------------------------------- plumbing

    private function basename(): string
    {
        return plugin_basename(dirname(__DIR__, 2) . '/wp-mini-laravel.php');
    }

    /**
     * Include the entry file the way `plugin_sandbox_scrape()` does, with a fake
     * database already in place so the migration engine has something to talk to.
     */
    private function installDatabase(): FakeWpdb
    {
        $db = new FakeWpdb();
        $GLOBALS['wpdb'] = $db;

        require dirname(__DIR__, 2) . '/wp-mini-laravel.php';

        return $db;
    }
}
