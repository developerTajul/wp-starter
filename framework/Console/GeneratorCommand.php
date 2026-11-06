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
     * The stub to render — a filename, or an absolute path.
     *
     * A bare filename (`controller.stub`) is resolved through {@see StubLocator},
     * which looks in the project first and falls back to the framework's own
     * directory. That is the form every built-in generator uses, and the only one
     * that survives the framework being installed under `vendor/`.
     *
     * An absolute path is used verbatim, with no fallback. That escape hatch
     * exists for a generator whose stub genuinely lives somewhere the two
     * directories do not cover — and for tests, which point a subclass at a
     * throwaway file. See {@see locateStub()} for why the two are distinguished
     * by the string rather than by a second abstract method.
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
        $stubPath = $this->locateStub();

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
     * Turn {@see getStubPath()} into a real path on disk.
     *
     * ## Why the absolute case is detected rather than declared
     *
     * The base class needs to tell "a stub name to look up" from "a path I was
     * given". The obvious design is a second abstract method — `isAbsolute()` or
     * a separate `getStubName()` — and it is worse: it is a second thing every
     * subclass must remember to implement, and a subclass that gets it wrong
     * fails at run time with a lookup for a file literally named `H:\...\x.stub`.
     * The string already carries the answer, so it is read rather than asked for.
     *
     * The rule mirrors {@see \Framework\Routing\RouteLoader}: a leading slash or
     * a drive letter means the caller gave a full path. Everything else is a
     * name, and a name is always a filename — never a relative path — because a
     * relative path would resolve against the process's working directory, which
     * under WP-CLI is wherever the operator happened to be standing.
     *
     * @throws StubNotFoundException When a name resolves nowhere.
     */
    protected function locateStub(): string
    {
        $stub = $this->getStubPath();

        if (str_starts_with($stub, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $stub) === 1) {
            return $stub;
        }

        return $this->stubLocator()->resolve($stub);
    }

    /**
     * The locator, aimed at this project's root.
     *
     * Built here rather than injected, because {@see GeneratorCommand} is
     * constructed by the container from each generator's own constructor — and
     * several generators declare none — so an injected dependency would have to
     * be threaded through every one of them to say something none of them needs
     * to vary. A locator is two strings and a `file_exists`; there is nothing to
     * substitute.
     *
     * `base_path()` is still the source of the project directory, so a project
     * that rebinds `path.base` moves its overridable stubs with it. It is only
     * called when a name needs looking up, so a generator given an absolute stub
     * path never touches the container binding at all.
     */
    protected function stubLocator(): StubLocator
    {
        return new StubLocator($this->projectPath());
    }

    /**
     * The directory project stubs are looked for under.
     *
     * Split out so a test can aim the locator at a workspace without rebinding
     * `path.base` for the whole process — the generators' own tests already do
     * the latter, and a second mechanism that agreed with it by accident would
     * make the override untestable.
     */
    protected function projectPath(): string
    {
        if (!function_exists('base_path')) {
            throw new RuntimeException(
                'base_path() is unavailable, so a stub name cannot be resolved against the project. '
                . 'Pass an absolute stub path instead.'
            );
        }

        return base_path();
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
