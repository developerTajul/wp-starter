<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Localization;

use Core\Shared\Domain\Contracts\TranslatorContract;

/**
 * Reads translation keys from `lang/<locale>/<module>.php`.
 *
 * Key-based rather than source-string based, matching the Laravel build: Core
 * asks for `companies.created`, and the same key resolves in either project, so
 * the domain keeps no user-facing wording of its own.
 *
 * @implements TranslatorContract
 */
final class WpTranslator implements TranslatorContract
{
    /**
     * Lines already read, keyed by module.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $cache = [];

    /**
     * The locale the files are read for, resolved once.
     *
     * Resolved in the constructor rather than per lookup: `determine_locale()`
     * touches several WordPress options, and a request resolves many keys.
     *
     * @param string      $langPath Absolute path to the `lang` directory.
     * @param string|null $locale   Overrides the detected locale; the seam a
     *                              test or a console command uses, since
     *                              `determine_locale()` needs a loaded install.
     */
    public function __construct(
        private readonly string $langPath,
        private ?string $locale = null,
    ) {
    }

    /**
     * {@inheritDoc}
     *
     * A missing file or key returns the key unchanged, which is what the
     * contract promises and what keeps a missing translation a visible ``
     * `companies.created`` rather than an empty string on screen.
     *
     * @param string                $key
     * @param array<string, string> $replace
     *
     * @return string
     */
    public function translate(string $key, array $replace = []): string
    {
        $line = $this->line($key);

        if ($line === null) {
            return $key;
        }

        return $replace === [] ? $line : $this->substitute($line, $replace);
    }

    /**
     * The text for a key, or null when no file or line carries it.
     *
     * The key is `<module>.<name>`, so the first segment names the file and the
     * rest is the array key inside it. A nested key would need the file's own
     * shape; these files are flat, which is why the remainder is not split
     * further.
     *
     * @param string $key
     *
     * @return string|null
     */
    private function line(string $key): ?string
    {
        $separator = strpos($key, '.');

        if ($separator === false || $separator === 0) {
            return null;
        }

        $module = substr($key, 0, $separator);
        $name = substr($key, $separator + 1);

        if ($name === '') {
            return null;
        }

        $lines = $this->lines($module);

        // A replacement whose value is itself a key is resolved by the caller,
        // not here, so a string is the only value that can come back.
        return isset($lines[$name]) && is_string($lines[$name]) ? $lines[$name] : null;
    }

    /**
     * The module's lines, read once per module.
     *
     * The file is a PHP script returning a flat `key => text` array. It is
     * included rather than parsed so the same file works unchanged in the
     * Laravel build, where `trans()` reads it too.
     *
     * @param string $module
     *
     * @return array<string, mixed>
     */
    private function lines(string $module): array
    {
        if (array_key_exists($module, $this->cache)) {
            return $this->cache[$module];
        }

        // A module name reaches the filesystem, so it is refused unless it is a
        // plain identifier. Without this, a key such as `../../wp-config` would
        // read a file the caller had no business naming.
        if (preg_match('/^[A-Za-z0-9_]+$/', $module) !== 1) {
            return $this->cache[$module] = [];
        }

        $file = $this->langPath . DIRECTORY_SEPARATOR . $this->locale() . DIRECTORY_SEPARATOR . $module . '.php';

        if (! is_file($file)) {
            return $this->cache[$module] = [];
        }

        $lines = require $file;

        return $this->cache[$module] = is_array($lines) ? $lines : [];
    }

    /**
     * The locale to read, detected from WordPress when not given.
     *
     * `determine_locale()` is the front-end locale, which is the one a screen's
     * wording should follow; `get_locale()` is the admin-independent default.
     *
     * @return string
     */
    private function locale(): string
    {
        if ($this->locale !== null) {
            return $this->locale;
        }

        if (function_exists('determine_locale')) {
            return $this->locale = determine_locale();
        }

        return $this->locale = 'en_US';
    }

    /**
     * Substitutes `:name` placeholders.
     *
     * The colon form matches what the Laravel build's `trans()` accepts, so one
     * lang file serves both.
     *
     * @param string                $line
     * @param array<string, string> $replace
     *
     * @return string
     */
    private function substitute(string $line, array $replace): string
    {
        foreach ($replace as $token => $value) {
            $line = str_replace(':' . $token, $value, $line);
        }

        return $line;
    }
}
