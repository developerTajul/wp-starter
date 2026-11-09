<?php
declare(strict_types=1);

namespace Framework\Console;

/**
 * Finds the stub a `make:*` command should render.
 *
 * Why this is not `base_path('stubs/…')`
 *
 * The framework ships its default stubs beside its own source, inside the
 * `Framework\` package. `base_path()` points at *the project that consumes the
 * framework*, which is the plugin root in a normal install - so resolving a stub
 * through it assumes the two directories are the same. They are not once the
 * framework is a Composer dependency: the stubs live under `vendor/`, the base
 * path does not, and `base_path('stubs/controller.stub')` names a file that was
 * never installed. Every generator then fails with "Stub file not found",
 * including the two that have nothing to do with stubs the user wrote.
 *
 * So a stub has two possible homes and this class knows both:
 *
 *   1. `<base path>/stubs/<name>` - the project's own copy, which wins when it
 *      exists so a project can customize generated code without patching a
 *      vendored file (which the next `composer update` would discard anyway);
 *   2. the framework's directory, resolved relative to this file rather than to
 *      any bound path, which is what makes the fallback independent of where the
 *      package was installed.
 *
 * Why overriding is deliberate rather than incidental
 *
 * PHP's own `include_path`/`file_exists` convention is to prefer the first match
 * and stay silent about it, which is fine for a lookup and wrong for a generator:
 * a project that copies a stub in, edits it, and later wonders why its edits
 * apply to one command and not another has no way to ask. {@see resolve()} keeps
 * the choice, and {@see source()} exists so a command *can* say where a stub came
 * from when the answer is not obvious.
 *
 * Why a missing stub is an exception and not a null
 *
 * Every caller turns "not found" into the same message, and every one of them is
 * a packaging bug rather than user input - there is no recovery path to branch
 * on. Throwing here means the message is written once, names both paths that were
 * searched, and cannot be forgotten by a caller that only checked one.
 */
final class StubLocator
{
    /**
     * The project's own stub directory, relative to the base path.
     */
    public const PROJECT_DIRECTORY = 'stubs';

    /**
     * The framework's default stub directory, relative to this file.
     *
     * `__DIR__` is `<framework>/Console`, so the defaults sit one level up: inside
     * the framework package, which is what makes the fallback work when that
     * package is installed under `vendor/`.
     *
     * Why the stubs live in the framework package and not at the plugin root
     *
     * They used to sit at the plugin root, beside `app/`, and that is the layout
     * this whole class exists to stop depending on. `base_path()` resolves to the
     * *consuming* project, so a stub at the project root is a stub that exists
     * only in the project that happens to be the template - the framework's own
     * package shipped none, and `__DIR__`-relative resolution would have looked
     * in `vendor/devlinkstudios/wp-mini-laravel-framework/stubs/` and found
     * nothing. Shipping them here means the default is always present, and the
     * project path below is an *override* rather than a requirement.
     *
     * Held as a relative segment rather than an absolute string because the
     * absolute path differs per install, and the point of this class is that it
     * does not need to be known.
     */
    private const FRAMEWORK_DIRECTORY = __DIR__ . '/../stubs';

    /**
     * Where the project's stubs live.
     *
     * Injected rather than read through `base_path()` so the locator stays usable
     * outside a booted plugin - a test, or a project that bound its base path
     * differently - and so the two candidates are symmetrical: both are just
     * directories this class was told about.
     */
    public function __construct(private readonly string $projectPath)
    {
    }

    /**
     * The path of the stub to render.
     *
     * @param string $name Filename only, e.g. "controller.stub". A path is
     *                     rejected rather than joined: the two candidate
     *                     directories are the whole lookup, and accepting
     *                     `../` here would let a name escape both.
     *
     * @throws StubNotFoundException When neither candidate exists.
     */
    public function resolve(string $name): string
    {
        return $this->resolvePreferred($name)
            ?? throw StubNotFoundException::notFound($name, $this->candidates($name));
    }

    /**
     * Whether a stub can be resolved at all, without throwing.
     */
    public function has(string $name): bool
    {
        return $this->resolvePreferred($name) !== null;
    }

    /**
     * Which candidate a name resolves to: 'project' or 'framework'.
     *
     * Null when neither exists. Reported as a word rather than a boolean because
     * the useful question is not *whether* a project stub is in play but *which*
     * one is - the answer to "why did my edit do nothing" is exactly this value.
     */
    public function source(string $name): ?string
    {
        $project = $this->projectCandidate($name);

        if ($this->isUsable($project)) {
            return 'project';
        }

        return $this->isUsable($this->frameworkCandidate($name)) ? 'framework' : null;
    }

    /**
     * Every path a name was looked for in, in the order searched.
     *
     * @return list<string>
     */
    public function candidates(string $name): array
    {
        return [$this->projectCandidate($name), $this->frameworkCandidate($name)];
    }

    /**
     * The framework's own default stub directory.
     */
    public static function frameworkDirectory(): string
    {
        return self::FRAMEWORK_DIRECTORY;
    }

    private function resolvePreferred(string $name): ?string
    {
        // Project first, then framework - the order is the contract, not an
        // implementation detail: it is what lets a project override a stub.
        foreach ($this->candidates($name) as $candidate) {
            if ($this->isUsable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function projectCandidate(string $name): string
    {
        return rtrim($this->projectPath, '/\\') . '/' . self::PROJECT_DIRECTORY . '/' . $name;
    }

    private function frameworkCandidate(string $name): string
    {
        return self::FRAMEWORK_DIRECTORY . '/' . $name;
    }

    /**
     * Whether a path names a file that can actually be read.
     *
     * `is_file` rather than `file_exists`: a directory named `controller.stub`
     * would satisfy `file_exists` and then fail at `file_get_contents`, which
     * reports "failed to open stream" instead of naming the real problem. The
     * readability check catches the other half - a vendored package installed
     * without read permission.
     */
    private function isUsable(string $path): bool
    {
        return is_file($path) && is_readable($path);
    }
}
