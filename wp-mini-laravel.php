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

// Idempotency guard: WordPress can include a plugin file more than once in a
// request, and every line below this point re-runs if it does. Set only once
// the dependencies are known to be present, so a failed vendor check does not
// flag the plugin as booted for the rest of a long-running process.
if (defined('WP_MINI_LARAVEL_BOOTED')) {
    return;
}

define('WP_MINI_LARAVEL_BOOTED', true);

\Framework\Config\Config::load(__DIR__ . '/config');

// Installing the container is app()'s job; touching app() here installs it.
// Kernel is inlined so it does not leak into the global scope.
(new \App\Kernel(app()))->bootstrap();

// Safely boot the container at the correct WordPress lifecycle hook
add_action('plugins_loaded', function () {
    app()->boot();
});