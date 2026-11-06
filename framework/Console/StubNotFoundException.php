<?php
declare(strict_types=1);

namespace Framework\Console;

use RuntimeException;

/**
 * Raised when a generator's stub cannot be found in either location.
 *
 * A subclass of {@see RuntimeException} on purpose: {@see GeneratorCommand}
 * catches that type around the whole render-and-write path and reports it as a
 * WP-CLI error, so a stub lookup participates in the existing failure handling
 * without the base class needing to know this exception exists. A generator that
 * declares its own stub path directly still gets the same treatment.
 */
final class StubNotFoundException extends RuntimeException
{
    /**
     * @param list<string> $searched Every path tried, in search order.
     */
    public static function notFound(string $name, array $searched): self
    {
        // Both paths are printed, and both are printed the way this framework
        // prints paths everywhere else. The alternative — naming only the
        // project path — produces "Stub file not found at: /site/stubs/x.stub",
        // which reads as "the file you should have written is missing" and sends
        // the reader to create a stub that the framework already ships.
        $paths = implode(
            "\n  ",
            array_map(static fn (string $path): string => self::normalize($path), $searched)
        );

        return new self(sprintf(
            'Stub file [%s] was not found in either location searched, in this order:'
            . "\n  %s\n"
            . 'Restore the file, or pass a stub name that exists. The second path is the framework\'s '
            . 'own default directory; if it is missing, the package was installed without its stubs.',
            $name,
            $paths
        ));
    }

    private static function normalize(string $path): string
    {
        return function_exists('wp_normalize_path') ? wp_normalize_path($path) : $path;
    }
}
