<?php
declare(strict_types=1);

/**
 * Plugin Name: WP Mini Laravel
 * Description: Enterprise-Grade Framework-agnostic WordPress plugin architecture.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * License: MIT
 */

if (!defined('ABSPATH')) {
    exit;
}

// Dependencies are not committed, so a fresh clone has no vendor/. Show an
// admin notice instead of letting the require below fatal with an uncaught Error.
if (! file_exists(__DIR__ . '/vendor/autoload.php')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>WP Mini Laravel:</strong> '
            . '<code>vendor/autoload.php</code> is missing. Please run '
            . '<code>composer install</code>.</p></div>';
    });

    return;
}

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables from the project root. `safeLoad()` is used so
// the plugin never crashes on a host where a `.env` file does not exist — in
// that case server-level environment variables are relied on instead. A
// `load()` would throw if `.env` were missing, which breaks production
// deployments that supply configuration at the platform level.
if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}

// Idempotency guard: WordPress can include a plugin file more than once in a
// request, and every line below this point re-runs if it does. Set only once
// the dependencies are known to be present, so a failed vendor check does not
// flag the plugin as booted for the rest of a long-running process.
if (defined('WP_MINI_LARAVEL_BOOTED')) {
    return;
}

define('WP_MINI_LARAVEL_BOOTED', true);

// Bind the plugin root before anything can ask for a path. base_path(),
// app_path() and config_path() all resolve through this key and fail fast
// when it is missing, so the console commands that build paths (make:provider)
// would otherwise throw. Bound here, in the entry file, because this is the
// only place that knows where the installation actually lives.
app()->instance('path.base', __DIR__);

\Framework\Config\Config::load(__DIR__ . '/config');

// Installing the container is app()'s job; touching app() here installs it.
// Kernel is inlined so it does not leak into the global scope.
(new \App\Kernel(app()))->bootstrap();

// Register the plugin's lifecycle hooks, and boot the container at the correct
// WordPress lifecycle hook.
//
// ## Why the registration is HERE and not inside the `plugins_loaded` callback
//
// This was inside that callback and it did not work — activation ran no
// migrations and reported success. The reason is the boot order, which is worth
// stating because it is not the order one assumes:
//
//  1. `wp-settings.php` includes the *active* plugins (line 582) and only then
//     fires `plugins_loaded` (line 630). A plugin being activated is not active
//     yet, so it is not in that list, is not included, and has no callbacks
//     registered when the action fires. The action never fires again in the
//     request.
//  2. The admin posts to `plugins.php`, which calls `activate_plugin()`.
//  3. `activate_plugin()` calls `plugin_sandbox_scrape()`, which does
//     `include_once WP_PLUGIN_DIR . '/' . $plugin`. **This is the first time
//     the entry file runs.**
//  4. Core then fires `do_action( "activate_{$plugin}", $network_wide )`.
//
// So by step 4 a `plugins_loaded` callback registered in step 3 has never run
// and never will, and `register_activation_hook()` is never called. Nothing
// complains: the plugin activates, WordPress redirects back to the plugin list,
// and the tables are simply absent. Registering at include time — which is step
// 3, and happens before step 4 — is the only placement that works.
//
// `ActivationBootOrderTest` reproduces steps 1–4 in order and fails against the
// previous placement.
//
// ## Why the boot is still deferred
//
// Registering the hooks here does not mean booting here. `app()->boot()` stays
// on `plugins_loaded`, so an ordinary request only pays for the container when
// WordPress says the plugins are ready; putting the boot here too would run
// every provider's `boot()` on every request that loads the file.
//
// The activation callback boots the container itself, because a real activation
// request reaches step 4 without `plugins_loaded` having fired for this plugin —
// so without that, no provider's `boot()` would ever run for an activation.
//
// ## Two smaller details
//
//  - **`__FILE__` is required, not convenient.** `register_*_hook()` derives the
//    plugin's basename from the path it is given and returns without registering
//    when the result has no directory separator — which is how it rejects a path
//    outside `WP_PLUGIN_DIR`. A rebrander that renames this file does not touch
//    `__FILE__`, so the identity survives a rebrand for free.
//
//  - **Uninstall deliberately does not use `register_uninstall_hook()`.** That
//    function stores its callback in the `uninstall_plugins` option — a
//    registration kept in the very database being torn down — so deleting the
//    plugin leaves a stale entry naming a callback the autoloader can no longer
//    find. `uninstall.php` at the plugin root is the other form WordPress
//    supports and stores nothing. See that file.
//
// The callbacks bind the container rather than passing the lifecycle object
// alone, because WordPress calls a hook with the arguments *it* chose and those
// are none: `activate_plugin()` and `deactivate_plugins()` invoke the registered
// callback with no parameters. A plain `[$lifecycle, 'activate']` would raise
// `ArgumentCountError` on every activation — a fatal, in the one request where
// the operator is least able to see why.
//
// The lifecycle object is resolved lazily, at call time, so a project that
// rebinds it keeps control and the resolution reflects the container as it is
// when the hook fires.
if (function_exists('register_activation_hook') && function_exists('register_deactivation_hook')) {
    register_activation_hook(__FILE__, static function (): void {
        app()->resolve(\App\Lifecycle\PluginLifecycle::class)->activate(app());
    });

    register_deactivation_hook(__FILE__, static function (): void {
        app()->resolve(\App\Lifecycle\PluginLifecycle::class)->deactivate(app());
    });
}

add_action('plugins_loaded', function () {
    app()->boot();
});