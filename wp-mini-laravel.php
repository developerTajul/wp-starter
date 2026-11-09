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

// Dependencies are not committed, so a fresh clone has no vendor/.
if (! file_exists(__DIR__ . '/vendor/autoload.php')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>WP Mini Laravel:</strong> '
            . '<code>vendor/autoload.php</code> is missing. Please run '
            . '<code>composer install</code>.</p></div>';
    });

    return;
}

require_once __DIR__ . '/vendor/autoload.php';

// `safeLoad()` rather than `load()`: a missing .env must not throw, because
// production deployments supply configuration at the platform level.
if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}

// WordPress can include a plugin file more than once per request.
if (defined('WP_MINI_LARAVEL_BOOTED')) {
    return;
}

define('WP_MINI_LARAVEL_BOOTED', true);

// path.base must be bound before anything asks for a path: base_path(),
// app_path() and config_path() resolve through it and fail fast when missing.
app()->instance('path.base', __DIR__);

\Framework\Config\Config::load(__DIR__ . '/config');

// Resolving app() installs the container. Kernel is inlined so it does not
// leak into the global scope.
(new \App\Kernel(app()))->bootstrap();

// Registration must happen at include time, not inside a `plugins_loaded`
// callback: `activate_plugin()` includes this file via `plugin_sandbox_scrape()`
// before firing `activate_{$plugin}`, and the plugin is not yet in the
// active-plugins list, so a `plugins_loaded` callback would never run and the
// hook would never register. It fails silently, reporting a successful
// activation with no tables created.
//
// Booting stays deferred to `plugins_loaded` so an ordinary request only pays
// for the container once plugins are ready; the activation callback boots it
// itself, since activation never reaches `plugins_loaded` for this plugin.
if (function_exists('register_activation_hook') && function_exists('register_deactivation_hook')) {
    register_activation_hook(__FILE__, static function (): void {
        app()->resolve(\Framework\Lifecycle\PluginLifecycle::class)->activate(app());
    });

    register_deactivation_hook(__FILE__, static function (): void {
        app()->resolve(\Framework\Lifecycle\PluginLifecycle::class)->deactivate(app());
    });
}

add_action('plugins_loaded', function () {
    app()->boot();
});