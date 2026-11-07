<?php
declare(strict_types=1);

namespace Framework\Console\Commands;

use Framework\Config\Config;
use Framework\Console\GeneratorCommand;

/**
 * `wp wp_mini make:command SendDigest` — scaffold a project CLI command.
 *
 * ## What this closes
 *
 * {@see \App\Console\Command} exists so generated commands extend something in
 * the application's own namespace, where project-wide output conventions have a
 * home. Without a generator, nothing ever extended it: the class was a documented
 * extension point with no way to reach it, and every project that wanted a
 * command wrote one by hand against the framework base and quietly skipped the
 * layer the framework provided.
 *
 * ## Why the stub class name drives the command name
 *
 * The generated class is `SendDigestCommand`, and the command it registers is
 * `send-digest` — derived here rather than asked for separately, because two
 * names for one thing is a chance to disagree. The derivation is in
 * {@see commandName()}.
 *
 * The class name has a `Command` suffix stripped for the command name but kept
 * on the class, which matches how Laravel names commands and is what makes the
 * file easy to find later. A caller who genuinely wants both to read `check` can
 * name the class `CheckCommand` — the suffix is stripped from the command name,
 * not required.
 */
class MakeCommandCommand extends GeneratorCommand
{
    /**
     * Suffix stripped from the class name to build the command name.
     */
    private const SUFFIX = 'Command';

    public function getName(): string
    {
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' make:command';
    }

    public function getDescription(): string
    {
        return 'Create a new WP-CLI command class.';
    }

    protected function getType(): string
    {
        return 'Command';
    }

    /**
     * Commands live beside the kernel that registers them, so the generated file
     * lands next to the list it has to be added to.
     */
    protected function getNamespace(): string
    {
        return config('app.namespace', Config::DEFAULT_NAMESPACE) . '\\Console\\Commands';
    }

    protected function getStubPath(): string
    {
        // Bare name: StubLocator prefers the project's stubs/command.stub and
        // falls back to the framework's, so this works from vendor/ too.
        return 'command.stub';
    }

    protected function getTargetPath(string $className): string
    {
        return app_path('Console/Commands/' . $this->subDirectoryPath() . $className . '.php');
    }

    /**
     * @return array<string, string>
     */
    protected function getReplacements(string $className): array
    {
        return [
            // The WP-CLI verb, without the CLI prefix — the stub adds that from
            // config, so the two cannot disagree about which prefix is current.
            '{{ command }}' => $this->commandName($className),
            // The stub's docblock names the command that produced it. Substituted
            // rather than left as a literal, because the base class only knows
            // `namespace` and `class`: any other `{{ … }}` in a stub survives into
            // the generated file, and a docblock reading `{{ cli_prefix }}
            // make:command` is exactly the kind of small wrongness that makes a
            // scaffold look broken. `MakeMigrationCommand` passes the same key for
            // the same reason.
            '{{ cli_prefix }}' => (string) config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX),
        ];
    }

    protected function getPostWriteHint(): ?string
    {
        return 'Register it in the $commands array of App\\Console\\Kernel to expose it to WP-CLI.';
    }

    /**
     * `SendDigestCommand` → `send-digest`.
     *
     * Kebab-case because that is what WP-CLI command verbs look like, and because
     * a camelCase verb in a shell is a word nobody can type without stopping to
     * think about the capital. An acronym run is kept together: `SendHTTPDigest`
     * becomes `send-http-digest`, not `send-h-t-t-p-digest`, which is unreadable
     * and was the actual bug in the first cut of this method.
     *
     * A name that reduces to nothing — the class is literally `Command` — falls
     * back to the class name lowercased rather than emitting a bare prefix, which
     * would register the *namespace* as a command and collide with every sibling.
     */
    private function commandName(string $className): string
    {
        $name = $className;

        if (str_ends_with($name, self::SUFFIX) && $name !== self::SUFFIX) {
            $name = substr($name, 0, -strlen(self::SUFFIX));
        }

        // Boundary one: a lower/digit followed by an upper, and the last capital
        // of an acronym run followed by a capital-then-lower ("HTTPDigest" splits
        // before the D, not after each T).
        $name = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $name);
        $name = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $name);

        $name = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));

        return $name === '' ? strtolower($className) : $name;
    }
}
