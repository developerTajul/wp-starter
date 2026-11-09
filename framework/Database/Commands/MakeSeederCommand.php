<?php
declare(strict_types=1);

namespace Framework\Database\Commands;

use Framework\Config\Config;
use Framework\Console\GeneratorCommand;

/**
 * `wp wp_mini make:seeder LeadSeeder` - scaffold a database seeder.
 *
 * The simplest generator in the project: one stub, one destination directory, no
 * flags.
 *
 * The class name is whatever the caller typed
 *
 * No `Seeder` suffix is appended, and no case is normalized - `make:seeder Blog`
 * writes `Blog.php` declaring `Blog`, exactly as `make:command` writes
 * `SendDigest.php`. That is the base class's rule and it is the right one: the
 * caller decides what their class is called, and a generator that silently
 * renamed its output would leave the developer looking for a file that does not
 * exist under the name they typed.
 *
 * Nothing downstream depends on the suffix either. Discovery maps filename to
 * class, and {@see \Framework\Database\Seeding\Seeder::getName()} strips a
 * `Seeder` suffix *if there is one*: `LeadSeeder` is selected as `lead` and
 * `Blog` as `blog`. Both are usable, and the convention is documented in the
 * README rather than enforced here.
 */
class MakeSeederCommand extends GeneratorCommand
{
    public function getName(): string
    {
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' make:seeder';
    }

    public function getDescription(): string
    {
        return 'Create a new database seeder class.';
    }

    protected function getType(): string
    {
        return 'Seeder';
    }

    /**
     * Seeders live beside each other in the project, not in the framework.
     *
     * `App\Database\Seeders`, which is the namespace
     * {@see \Framework\Database\Seeding\SeederRegistry} resolves discovered files
     * under. The two must agree or a generated seeder is a file nothing ever
     * finds, so the namespace is composed here from the same config key the
     * registry reads.
     */
    protected function getNamespace(): string
    {
        return config('app.namespace', Config::DEFAULT_NAMESPACE) . '\\Database\\Seeders';
    }

    protected function getStubPath(): string
    {
        // Bare name: StubLocator prefers the project's stubs/seeder.stub and falls
        // back to the framework's, so this works from vendor/ too.
        return 'seeder.stub';
    }

    protected function getTargetPath(string $className): string
    {
        return base_path('database/seeders/' . $this->subDirectoryPath() . $className . '.php');
    }

    /**
     * @return array<string, string>
     */
    protected function getReplacements(string $className): array
    {
        return [
            // The name `db:seed --class=` matches, derived from the class the stub
            // will declare rather than asked for separately: a seeder whose class
            // and selectable name disagree is a seeder nobody can run. The
            // derivation is `Seeder::getName()`'s, reproduced here because the
            // generator has to know the answer before the class exists.
            '{{ seeder }}' => $this->seederName($className),
            // The stub's docblock names the command that produced it. Any `{{ … }}`
            // the base class does not replace survives into the generated file, so
            // these are substituted rather than left literal.
            '{{ cli_prefix }}' => (string) config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX),
        ];
    }

    protected function getPostWriteHint(): ?string
    {
        return 'Run it with: wp ' . config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX)
            . ' db:seed --class=' . $this->seederName($this->lastClassName ?? '');
    }

    /**
     * The last class name this command resolved, for the post-write hint.
     *
     * The base class computes the class name during rendering and does not hand it
     * to `getPostWriteHint()`, so it is captured where it passes through. Kept
     * private and nullable: a hint is worth a small piece of state, and it is not
     * worth widening the base class's contract for every other generator.
     */
    private ?string $lastClassName = null;

    protected function render(string $className): string
    {
        $this->lastClassName = $className;

        return parent::render($className);
    }

    /**
     * The selectable name, matching {@see \Framework\Database\Seeding\Seeder::getName()}.
     *
     * The `Seeder` suffix is stripped when present, because `LeadSeeder` and
     * `lead` should be the same seeder - the class name is the conventional one
     * and the command line is written in lower case. A class without the suffix
     * is used unchanged, so `Blog` is selected as `blog`.
     *
     * The same guard the base class applies: a class named literally `Seeder`
     * keeps its name rather than being stripped to the empty string, which no
     * `--class=` could ever match.
     */
    private function seederName(string $className): string
    {
        $short = $className;

        if (str_ends_with($short, 'Seeder') && $short !== 'Seeder') {
            $short = substr($short, 0, -strlen('Seeder'));
        }

        return strtolower($short);
    }
}
