<?php
declare(strict_types=1);

namespace Framework\Console;

use Framework\Config\Config;
use RuntimeException;

/**
 * Base class for the `make:*` scaffolding commands.
 *
 * Every generator does the same thing in the same order — validate a class name,
 * locate a stub, refuse to clobber an existing file, substitute placeholders,
 * create the directory, write — and differs only in *which* stub and *where*.
 * Subclasses supply those two facts and inherit the rest.
 *
 * Failure is reported by throwing {@see RuntimeException}; {@see handle()} turns
 * that into a WP-CLI error. Subclasses therefore never return early on a
 * validation failure, which is what silently lets a broken generator pass CI.
 */
abstract class GeneratorCommand extends Command
{
    /**
     * Absolute path to the stub to render.
     */
    abstract protected function getStubPath(): string;

    /**
     * Absolute path the generated file should be written to.
     */
    abstract protected function getTargetPath(string $className): string;

    /**
     * Human-readable kind, used to build messages: "Provider", "Controller".
     */
    abstract protected function getType(): string;

    /**
     * Placeholder => value pairs substituted into the stub.
     *
     * The common `class` and `namespace` replacements are applied by
     * {@see render()}; override this for anything type-specific.
     *
     * @return array<string, string>
     */
    protected function getReplacements(string $className): array
    {
        return [];
    }

    /**
     * Post-write instructions, e.g. "register it in config/app.php".
     */
    protected function getPostWriteHint(): ?string
    {
        return null;
    }

    /**
     * What to tell the caller when no usable name was given.
     *
     * Overridable because the argument is not a class name for every generator:
     * `make:migration` takes a free-form description and derives the class from
     * it, so the default example would be actively misleading.
     */
    protected function missingNameMessage(): string
    {
        return sprintf(
            'Please provide a %s name. Example: %s Blog',
            strtolower($this->getType()),
            $this->getName()
        );
    }

    /**
     * Namespace new classes of this type are placed in.
     */
    protected function getNamespace(): string
    {
        return (string) config('app.namespace', Config::DEFAULT_NAMESPACE);
    }

    public function handle(array $args, array $assocArgs): void
    {
        try {
            $className = $this->resolveClassName($args[0] ?? null);

            if ($className === null) {
                $this->error($this->missingNameMessage());

                return;
            }

            $targetPath = $this->getTargetPath($className);

            if (file_exists($targetPath)) {
                $this->error(sprintf('%s already exists at: %s', $this->getType(), $this->normalizePath($targetPath)));

                return;
            }

            $this->write($targetPath, $this->render($className));
        } catch (RuntimeException $e) {
            // A generator reports failure the WP-CLI way — a message, not a
            // stack trace — so every anticipated problem is thrown as a
            // RuntimeException and caught here.
            $this->error($e->getMessage());

            return;
        }

        $this->success(sprintf('%s created successfully: %s', $this->getType(), $this->normalizePath($targetPath)));

        $hint = $this->getPostWriteHint();

        if ($hint !== null) {
            $this->info($hint);
        }
    }

    /**
     * Validate and normalize the class name given on the command line.
     *
     * Returns null when no name was supplied at all; throws when a name was
     * supplied but is not usable as a class name.
     *
     * @throws RuntimeException
     */
    protected function resolveClassName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        // Class names cannot carry path separators or a .php suffix, and a
        // `Blog/Index` argument is as likely to mean "BlogIndex" as it is to be
        // a mistake — so strip both rather than reject the input.
        $className = (string) preg_replace('/\.php$/i', '', str_replace(['\\', '/'], '', $name));

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $className) !== 1) {
            throw new RuntimeException(sprintf(
                'Invalid %s name [%s]. Use letters, numbers and underscores, starting with a letter.',
                strtolower($this->getType()),
                $className
            ));
        }

        return $className;
    }

    /**
     * Produce the file contents for a class.
     *
     * @throws RuntimeException
     */
    protected function render(string $className): string
    {
        $stubPath = $this->getStubPath();

        if (!is_file($stubPath)) {
            throw new RuntimeException(sprintf('Stub file not found at: %s', $this->normalizePath($stubPath)));
        }

        $contents = file_get_contents($stubPath);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read stub file at: %s', $this->normalizePath($stubPath)));
        }

        $replacements = array_merge(
            [
                '{{ namespace }}' => $this->getNamespace(),
                '{{ class }}' => $className,
            ],
            $this->getReplacements($className)
        );

        return str_replace(array_keys($replacements), array_values($replacements), $contents);
    }

    /**
     * Create the parent directory when needed and write the file.
     *
     * @throws RuntimeException
     */
    protected function write(string $targetPath, string $contents): void
    {
        $directory = dirname($targetPath);

        if (!is_dir($directory) && !$this->makeDirectory($directory)) {
            throw new RuntimeException(sprintf(
                'Unable to create directory: %s',
                $this->normalizePath($directory)
            ));
        }

        if (file_put_contents($targetPath, $contents) === false) {
            throw new RuntimeException(sprintf(
                'Unable to write %s to: %s',
                strtolower($this->getType()),
                $this->normalizePath($targetPath)
            ));
        }
    }

    private function makeDirectory(string $directory): bool
    {
        // wp_mkdir_p() honours the install's filesystem permissions and is the
        // correct call inside WordPress; mkdir() is the fallback for a test or
        // CLI context where WordPress is not loaded.
        if (function_exists('wp_mkdir_p')) {
            return (bool) wp_mkdir_p($directory);
        }

        return mkdir($directory, 0755, true) || is_dir($directory);
    }

    /**
     * Render a path for display, preferring WordPress's normalization when it
     * is available so Windows paths show forward slashes like every other path
     * in a WP-CLI transcript.
     */
    protected function normalizePath(string $path): string
    {
        return function_exists('wp_normalize_path') ? wp_normalize_path($path) : $path;
    }
}
