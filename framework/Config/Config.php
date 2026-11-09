<?php
declare(strict_types=1);

namespace Framework\Config;

class Config
{
    /**
     * The values the library falls back to when configuration has not loaded.
     *
     * Why a fallback needs one home rather than being written at each call
     *
     * Every one of these appears as a literal default inside a `config()` call -
     * `config('app.cli_prefix', self::DEFAULT_CLI_PREFIX)`. That is fine while
     * there is one caller, and stops being fine the moment a second one needs
     * the same value: a test double, a generator stub, a debugging screen.
     *
     * The failure this prevents is quiet. Two copies of a fallback agree exactly
     * as long as nobody edits either one - and the configuration they stand in
     * for is absent under PHPUnit, WP-CLI and anything running before the entry
     * file boots, so the fallback is frequently the value *actually in use*
     * rather than a defensive branch that never runs. Two copies then disagree
     * silently, and the symptom is not an error about configuration: it is a
     * command appearing under the wrong name, or a flashed message reading back
     * as an empty string.
     *
     * These are the *library's* own identity, so unlike the identifiers in
     * `config/app.php` they are not rewritten by the rebrand script. A project
     * that wants its own values sets them in config, where they win.
     */
    public const DEFAULT_NAMESPACE = 'App';

    public const DEFAULT_CLI_PREFIX = 'wp_mini';

    public const DEFAULT_PREFIX = 'wp_mini_laravel_';

    public const DEFAULT_REST_NAMESPACE = 'wp-mini-laravel/v1';

    public const DEFAULT_TEXT_DOMAIN = 'wp-mini-laravel';

    protected static array $items = [];
    protected static array $loadedPaths = [];

    public static function load(string $path): void
    {
        // A path is loaded at most once, so a second load() of the same
        // directory is a no-op while a different directory still loads.
        // Bail before glob(): a bad $path would otherwise match nothing and
        // leave an empty config that looks successfully loaded.
        if (in_array($path, self::$loadedPaths, true) || !is_dir($path)) {
            return;
        }

        foreach (glob($path . '/*.php') as $file) {
            $key = basename($file, '.php');
            self::$items[$key] = require $file;
        }

        self::$loadedPaths[] = $path;
    }

    /**
     * Reset all loaded configuration.
     *
     * Intended for automated testing, where each case needs a clean config
     * state; production code should load configuration exactly once.
     */
    public static function flush(): void
    {
        self::$items = [];
        self::$loadedPaths = [];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $array = self::$items;

        if (isset($array[$key])) {
            return $array[$key];
        }

        if (strpos($key, '.') === false) {
            return $array[$key] ?? $default;
        }

        foreach (explode('.', $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }

        return $array;
    }
}