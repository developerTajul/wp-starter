<?php
declare(strict_types=1);

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use Framework\Config\Config;

if (! function_exists('env')) {
    /**
     * Get an item from the environment, falling back to a default.
     *
     * Mirrors Laravel's `env()` helper: values are looked up in `$_ENV`, then
     * `$_SERVER`, then `getenv()`. String booleans are coerced so
     * `env('APP_DEBUG')` returns a real PHP bool rather than the string
     * "true", and `(null)` / empty values resolve to null exactly like Laravel.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = _env_get_from_superglobals($key);

        // A missing key yields the caller's default verbatim — nothing is
        // coerced when there is no value to parse.
        if ($value === null) {
            return $default;
        }

        // Match Laravel's string-to-value coercion rules:
        //  - '(empty)'           -> ''  (nothing to change, already a string)
        //  - '(null)'            -> null
        //  - 'true'/'false'      -> (bool)
        //  - '(true)'/'(false)'  -> (bool)
        //  - ''                  -> null
        if (is_string($value)) {
            $normalized = strtolower($value);

            if ($normalized === '(null)' || $normalized === '(empty)') {
                return $normalized === '(empty)' ? '' : null;
            }

            if ($normalized === 'true' || $normalized === 'false') {
                return $normalized === 'true';
            }

            if ($normalized === '(true)' || $normalized === '(false)') {
                return $normalized === '(true)';
            }

            if ($value === '') {
                return null;
            }
        }

        return $value;
    }

    /**
     * Look a key up across the three sources Laravel consults, in order.
     *
     * `$_ENV` wins over `$_SERVER` only when PHP is configured to populate
     * `$_ENV` from the environment (various ini settings; some hosting panels
     * leave it empty). `getenv()` is the most reliable fallback.
     *
     * @param string $key
     * @return mixed
     */
    function _env_get_from_superglobals(string $key): mixed
    {
        if (array_key_exists($key, $_ENV)) {
            return $_ENV[$key];
        }

        if (array_key_exists($key, $_SERVER)) {
            return $_SERVER[$key];
        }

        $value = getenv($key);

        return $value === false ? null : $value;
    }
}

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

if (! function_exists('view')) {
    /**
     * Render a view, or build a response for one.
     *
     *     view('leads.index', ['leads' => $leads])          // -> ViewResponse
     *     view('emails.receipt', $data)->render()           // -> string
     *
     * Returning the response rather than the string is what makes the two-argument
     * call the common case: a controller returns it and the dispatcher sends it.
     * A caller that wants the markup asks for it explicitly, which reads as the
     * deliberate act it is.
     *
     * @param array<string, mixed> $data
     */
    function view(string $name = '', array $data = []): \Framework\Http\Responses\ViewResponse
    {
        /** @var \Framework\Support\ViewRenderer $renderer */
        $renderer = app(\Framework\Support\ViewRenderer::class);

        return $renderer->make($name, $data);
    }
}

if (! function_exists('e')) {
    /**
     * Escape a value for HTML output.
     *
     * The one helper a view needs on nearly every line, and the reason it exists
     * is that the alternative — reaching for `esc_html()` — is WordPress-specific
     * and unavailable the moment a view is rendered outside the CMS (a WP-CLI
     * export, an email built in a queue worker, a test). `esc_html()` is used when
     * it is loaded, so a view still gets WordPress's filters and translations;
     * `htmlspecialchars()` is the fallback that keeps the view renderable
     * anywhere.
     *
     * Quoting both quote styles is deliberate and not a default worth trusting:
     * a value interpolated into an attribute is escaped for a *different* context
     * than one in text, and `ENT_QUOTES` is the only mode that is correct in
     * both. Without it, `value="<?= e($x) ?>"` lets an apostrophe end the
     * attribute and inject a handler.
     *
     * A null renders as the empty string rather than the literal "null" — the
     * overwhelmingly common case is an optional field, and `value="null"` in a
     * form is never what was meant.
     *
     * @param mixed $value
     */
    function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        // A bool or an array has no sensible string form, and both are the kind
        // of value that reaches a template by accident. Rendering them as '' and
        // a JSON document respectively beats "1" and "Array".
        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $value = $encoded === false ? '' : $encoded;
        }

        $string = (string) $value;

        return function_exists('esc_html')
            ? esc_html($string)
            : htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('old')) {
    /**
     * A submitted value from the previous request, escaped for an attribute.
     *
     * The pair a form field actually needs — repopulate the value, escape it for
     * the context it lands in — collapsed into one call, because writing
     * `e(app(ViewRenderer::class)->oldValue('email'))` in every input is how a
     * project ends up with one input that forgot to.
     *
     * Returns `$default` when nothing was flashed, so a create form and an edit
     * form can share one template.
     *
     * @param mixed $default
     */
    function old(string $field, mixed $default = ''): string
    {
        try {
            /** @var \Framework\Support\ViewRenderer $renderer */
            $renderer = app(\Framework\Support\ViewRenderer::class);
        } catch (\Throwable) {
            return e($default);
        }

        return e($renderer->oldValue($field, $default));
    }
}