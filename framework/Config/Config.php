<?php
declare(strict_types=1);

namespace Framework\Config;

class Config
{
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