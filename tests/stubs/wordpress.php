<?php
declare(strict_types=1);

/**
 * The WordPress function surface the framework touches.
 *
 * Every stub records into (or reads from) Tests\Support\WpStub, so a test can
 * assert *that* a hook was registered and then fire it deliberately with
 * WpStub::doAction() — rather than the framework ever running WordPress's real
 * dispatch, which does not exist here.
 *
 * Only functions the framework actually calls are defined. Adding a stub for a
 * function nothing calls is a stub that will silently drift.
 */

use Tests\Support\WpStub;

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}

if (!function_exists('add_action')) {
    /**
     * @param callable $callback
     */
    function add_action(string $tag, $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        WpStub::$actions[$tag][] = $callback;

        return true;
    }
}

if (!function_exists('add_filter')) {
    /**
     * @param callable $callback
     */
    function add_filter(string $tag, $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        return add_action($tag, $callback, $priority, $acceptedArgs);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return WpStub::$currentUserCan;
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return WpStub::$userLoggedIn;
    }
}

if (!function_exists('rest_authorization_required_code')) {
    function rest_authorization_required_code(): int
    {
        return is_user_logged_in() ? 403 : 401;
    }
}

if (!function_exists('register_rest_route')) {
    /**
     * @param array<string, mixed>|array<int, array<string, mixed>> $args
     */
    function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
    {
        // WordPress normalizes a single argument set into a list before storing
        // it; mirroring that here keeps assertions in the tests simple.
        if (isset($args['callback']) || isset($args['methods'])) {
            $args = [$args];
        }

        foreach ($args as $registration) {
            WpStub::$registeredRoutes[$namespace][] = ['route' => $route] + $registration;
        }

        return true;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_normalize_path')) {
    function wp_normalize_path(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool
    {
        return is_dir($target) || mkdir($target, 0755, true);
    }
}

if (!function_exists('get_option')) {
    /**
     * @param mixed $default
     * @return mixed
     */
    function get_option(string $option, $default = false)
    {
        return WpStub::$options[$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    /**
     * @param mixed $value
     */
    function update_option(string $option, $value, bool $autoload = true): bool
    {
        WpStub::$options[$option] = $value;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        unset(WpStub::$options[$option]);

        return true;
    }
}
