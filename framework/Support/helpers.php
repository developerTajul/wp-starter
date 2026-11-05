<?php
declare(strict_types=1);

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use Framework\Config\Config;

if (! function_exists('app')) {
    /**
     * Resolve the plugin's service container, or a service from it.
     *
     * The static is per-process, so two plugins bundling this architecture each
     * get their own container instead of clobbering one another's bindings.
     *
     * @param string|null $abstract Identifier to resolve; null returns the container.
     * @return mixed The container itself, or the resolved service.
     */
    function app(?string $abstract = null): mixed
    {
        static $container = null;

        if ($container === null) {
            $container = new Container();

            // Self-binding: ServiceProvider type-hints the interface, and the
            // container refuses to guess at an unbound interface, so anything
            // resolved through the interface would otherwise fail. Binding the
            // concrete class too lets both spellings resolve to this instance.
            $container->instance(ContainerInterface::class, $container);
            $container->instance(Container::class, $container);
        }

        if ($abstract === null) {
            return $container;
        }

        return $container->resolve($abstract);
    }
}

if (! function_exists('base_path')) {
    /**
     * Get the installation's base path, or a path relative to it.
     *
     * The root is bound into the container as 'path.base' by the entry file.
     * Fails fast when that binding is missing rather than guessing a directory:
     * a wrong root would otherwise surface much later as files written to, or
     * read from, the wrong place.
     *
     * @param string $path
     * @return string
     * @throws \RuntimeException When 'path.base' has not been bound.
     */
    function base_path(string $path = ''): string
    {
        if (!app()->bound('path.base')) {
            throw new \RuntimeException('Base path is not bound in the container.');
        }

        return base_path_join((string) app('path.base'), $path);
    }
}

if (! function_exists('app_path')) {
    /**
     * Get the application path, or a path relative to it.
     *
     * @param string $path
     * @return string
     */
    function app_path(string $path = ''): string
    {
        return base_path_join(base_path(), 'app', $path);
    }
}

if (! function_exists('config_path')) {
    /**
     * Get the configuration path, or a path relative to it.
     *
     * @param string $path
     * @return string
     */
    function config_path(string $path = ''): string
    {
        return base_path_join(base_path(), 'config', $path);
    }
}

if (! function_exists('base_path_join')) {
    /**
     * Join path segments with the ONE separator the base path already uses.
     *
     * The base path belongs to the caller: it may be a native Windows path with
     * backslashes or a WordPress-supplied forward-slash path. Appending
     * DIRECTORY_SEPARATOR assumes the two agree, which they do not on Windows —
     * a forward-slash root would produce `H:/site/app\Providers`, and a literal
     * `\a` in that path is a Windows escape sequence, not a directory.
     *
     * Preferring a forward slash when the root already uses one also produces
     * stable, comparable strings on every platform, which matters to any test
     * or cache key built from a path.
     *
     * @param string $base  The root, without a trailing separator.
     * @param string ...$segments
     * @return string
     */
    function base_path_join(string $base, string ...$segments): string
    {
        $separator = str_contains($base, '\\') && !str_contains($base, '/')
            ? '\\'
            : '/';

        $base = rtrim($base, '/\\');

        foreach ($segments as $segment) {
            $segment = trim($segment, '/\\');

            if ($segment !== '') {
                $base .= $separator . $segment;
            }
        }

        return $base;
    }
}

if (! function_exists('config')) {
    /**
     * Get the specified configuration value.
     *
     * Read-only: $default is returned when the key is absent, it does not
     * write anything back to the configuration store.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}