<?php
declare(strict_types=1);

namespace Framework\Console;

use Framework\Config\Config;
use Framework\Support\ClassName;
use InvalidArgumentException;
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
     * The sub-namespace and sub-directory the current run is generating into.
     *
     * Set by {@see resolveClassName()} and read by {@see getNamespace()},
     * {@see subNamespace()} and {@see getTargetPath()}.
     *
     * ## Why this is state rather than an argument
     *
     * `getTargetPath(string $className)` is the subclass's contract, and by the
     * time a subclass sees it the caller's `Admin/CompanyController` has already
     * been reduced to `CompanyController` — the directory is gone. Threading the
     * parse through instead would change the signature of every generator, and,
     * worse, would let a subclass that ignored the new parameter keep compiling
     * while writing `CompanyController.php` into the *root* controllers folder
     * and declaring `Admin` in its namespace. That mismatch is invisible until
     * something tries to load the class: the file exists, the namespace parses,
     * and only Composer's lookup fails.
     *
     * Held here, the sub-namespace and the sub-directory are one value that the
     * base class appends to both, in the same pass. The two cannot disagree,
     * because a subclass never sees them separately.
     *
     * `protected` rather than `private` for the one generator that builds its own
     * class name from a description — see
     * {@see \Framework\Database\Commands\MakeMigrationCommand::resolveClassName()},
     * which parses the name itself and therefore has to publish the result.
     */
    protected ClassName $parsed;

    /**
     * The `--flag=value` arguments this run was invoked with.
     *
     * Published for the same reason {@see $parsed} is: `handle()` receives them
     * and the methods that need them — `getStubPath()`, `getReplacements()`,
     * `getPostWriteHint()` — are subclass overrides that take no argument of
     * their own, so a generator with a flag that changes *what* it generates has
     * nowhere else to read it from. Adding a parameter to any of those three
     * signatures would change the contract of every generator to serve the one
     * that has flags.
     *
     * Empty for a generator that takes none, which is every generator but
     * `make:controller` — so the property costs nothing and changes no existing
     * behaviour.
     *
     * ## Why the base class does not interpret it
     *
     * What a flag *means* is per-generator: `--resource` selects a file shape,
     * and a future flag might select a namespace. So the array is passed through
     * untouched and each generator reads the keys it knows. The one thing the
     * base class does provide is the spelling normalization — see
     * {@see assocArgs()} — because "is `--resource` the same key as `resource`"
     * is a question about WP-CLI dispatch, not about controllers.
     *
     * @var array<string, mixed>
     */
    protected array $assocArgs = [];

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
     *
     * The class name is the *file* name only; a caller-supplied sub-namespace is
     * appended by the base class rather than by the implementation. Use
     * {@see subDirectoryPath()} to get the directory this file belongs in, and
     * apply {@see subNamespacePrefix()} to a namespace this generator has already
     * assembled by hand.
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
     *
     * A caller-supplied sub-namespace — `make:controller Admin/CompanyController`
     * — is appended by {@see render()} rather than here, so a subclass that
     * overrides this still gets the sub-namespace without having to remember to
     * call `parent::getNamespace()`. A subclass that must *frame* the
     * sub-namespace instead of appending it, or that has assembled a namespace by
     * hand, uses {@see subNamespacePrefix()} directly.
     */
    protected function getNamespace(): string
    {
        return (string) config('app.namespace', Config::DEFAULT_NAMESPACE);
    }

    /**
     * The sub-namespace the current run is generating into — `Admin\Reports`, or
     * `''` for a bare name.
     *
     * Read by a subclass that has to place something *under* the sub-namespace
     * rather than beside it, and by the tests that pin the parse.
     */
    protected function subNamespace(): string
    {
        return $this->parsed->sub;
    }

    /**
     * Append the caller's sub-namespace to a namespace this generator assembled.
     *
     * For a generator whose classes live somewhere other than directly under
     * `app/` — {@see \Framework\Database\Commands\MakeMigrationCommand} builds
     * `App\Database\Migrations` — where the sub-namespace belongs *after* the
     * generator's own path rather than immediately after the project root.
     */
    protected function subNamespacePrefix(): string
    {
        return $this->parsed->namespacePrefix();
    }

    /**
     * The sub-directory the current run's file belongs in — `Admin/Reports/`, or
     * `''` for a bare name.
     *
     * The path spelling of {@see subNamespace()}, for a generator that resolves
     * its target directory through something other than a literal.
     */
    protected function subDirectoryPath(): string
    {
        return $this->parsed->pathPrefix();
    }

    public function handle(array $args, array $assocArgs): void
    {
        // Published before anything can throw, because a generator reads its
        // flags from inside getStubPath() — which handle() calls first, and which
        // is where a flag conflict is refused. Assigning after the try block
        // would mean the refusal was built from an empty array and never fired.
        $this->assocArgs = $assocArgs;

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
     * ## Why the parse happens here
     *
     * {@see handle()} calls this before it calls {@see getTargetPath()}, so this
     * is the only place that can populate {@see $parsed} in time for the target
     * path to include the sub-directory. Resolving the class name and resolving
     * its sub-namespace are one operation for that reason, not two.
     *
     * @throws RuntimeException
     */
    protected function resolveClassName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        try {
            $parsed = $this->parseName($name);
        } catch (InvalidArgumentException $e) {
            // The parse reports what is wrong with which segment, which is more
            // than a generic "invalid name" can say. It throws the SPL exception
            // because it is a value object with no opinion about how its caller
            // reports failures; translating it here keeps the generators'
            // "anticipated failure is a RuntimeException" contract intact.
            throw new RuntimeException(sprintf(
                'Invalid %s name [%s]. %s',
                strtolower($this->getType()),
                $name,
                $e->getMessage()
            ));
        }

        $this->parsed = $parsed;

        return $parsed->class;
    }

    /**
     * Whether a long flag was passed, in any of the spellings that reach here.
     *
     * `WP_CLI::runcommand()` hands a flag through as `['resource' => true]`,
     * while a hand-built invocation — a test, or a caller assembling the array
     * itself — may write `['resource' => 'true']` or `['--resource' => true]`.
     * All three mean the same thing to the developer who typed it, so all three
     * are accepted rather than only the one this framework's dispatch happens to
     * produce.
     *
     * A flag passed as `--resource=false` is *not* a request for the flag:
     * WP-CLI supplies that as the string `'false'`, and treating any non-empty
     * string as truthy is exactly how a `--flag=false` silently turns a feature
     * on. The falsy spellings are listed rather than the truthy ones for that
     * reason — an unrecognized value is far more likely to be a genuine
     * `--flag=something` than an attempt to say no.
     */
    protected function hasFlag(string $flag): bool
    {
        foreach ([$flag, '--' . $flag] as $key) {
            if (!array_key_exists($key, $this->assocArgs)) {
                continue;
            }

            $value = $this->assocArgs[$key];

            if (is_bool($value)) {
                return $value;
            }

            if (is_string($value) && in_array(strtolower(trim($value)), ['false', '0', 'no', ''], true)) {
                return false;
            }

            return $value !== null;
        }

        return false;
    }

    /**
     * The value of a long flag, or null when it was not given a usable one.
     *
     * The companion to {@see hasFlag()} for flags that carry a value —
     * `--table=leads`. A bare `--table` (no `=`) arrives as boolean `true`, which
     * is a request for the flag with no value and is reported as null rather than
     * cast to the string `'1'`, which would scaffold a table literally named `1`.
     *
     * The falsy spellings are treated the same way they are in {@see hasFlag()},
     * and for the same reason: `--table=false` is a caller saying no, not a caller
     * naming a table `false`.
     */
    protected function flagValue(string $flag): ?string
    {
        foreach ([$flag, '--' . $flag] as $key) {
            if (!array_key_exists($key, $this->assocArgs)) {
                continue;
            }

            $value = $this->assocArgs[$key];

            if (is_bool($value) || $value === null) {
                return null;
            }

            $value = trim((string) $value);

            if ($value === '' || in_array(strtolower($value), ['false', '0', 'no'], true)) {
                return null;
            }

            return $value;
        }

        return null;
    }

    /**
     * How a caller-supplied name is parsed into a class and its sub-namespace.
     *
     * Overridable because the argument is not a class name for every generator:
     * `make:migration` takes a free-form description like `create_leads_table`
     * and derives the class itself, so the strict upper-case rule that makes
     * `Admin/CompanyController` meaningful would reject its input outright.
     */
    protected function parseName(string $name): ClassName
    {
        return ClassName::parse($name);
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
                '{{ namespace }}' => $this->namespaceFor(),
                '{{ class }}' => $className,
            ],
            $this->getReplacements($className)
        );

        return str_replace(array_keys($replacements), array_values($replacements), $contents);
    }

    /**
     * The namespace written into the generated file.
     *
     * The base class appends the caller's sub-namespace here rather than inside
     * {@see getNamespace()} so that a subclass overriding it — which four of the
     * five generators do, because their classes do not live directly under
     * `app/` — still gets the sub-namespace without having to call
     * `parent::getNamespace()` and remember to append to the result.
     *
     * The append is unconditional, and that is what keeps it correct: the class
     * file is written into `<generator directory>/<sub>/<Class>.php` by
     * {@see subDirectoryPath()}, so the namespace must carry `<sub>` for the two
     * to agree. A generator appending the sub-namespace itself is the rejected
     * design — see the docblocks on {@see getNamespace()} and {@see $parsed}.
     *
     * The separator is added here rather than assumed on the end of
     * {@see getNamespace()}, because the generators spell their base namespace
     * without a trailing `\` — so a bare concatenation produces
     * `App\Http\ControllersAdmin`, which is a perfectly valid namespace name and
     * therefore fails silently. `namespacePrefix()` already carries the *trailing*
     * separator, which is why the one added here is only the divider between the
     * two parts. It is empty for a bare name, so the flat case is untouched
     * rather than gaining a doubled backslash.
     */
    private function namespaceFor(): string
    {
        $sub = $this->parsed->namespacePrefix();

        return $sub === '' ? $this->getNamespace() : $this->getNamespace() . '\\' . rtrim($sub, '\\');
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
