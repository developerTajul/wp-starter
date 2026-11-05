<?php
declare(strict_types=1);

namespace Framework\Database\Commands;

use Framework\Config\Config;
use Framework\Console\GeneratorCommand;
use Framework\Database\MigrationFile;
use RuntimeException;

/**
 * `wp wp_mini make:migration create_leads_table` — scaffold a migration.
 *
 * Differs from the other generators in one way that matters: the class name is
 * not what the caller typed. A migration is named
 * `<timestamp>_<description>` and its class is the StudlyCase of the description,
 * so the caller supplies a description and this command mints the timestamp,
 * derives the class, and passes both to the stub.
 */
class MakeMigrationCommand extends GeneratorCommand
{
    /**
     * The timestamp this run's filename carries, fixed per invocation.
     *
     * Held rather than recomputed so a caller that asks for the target path and
     * then the replacements does not get two different timestamps — which would
     * name the file and the class from different seconds, and the class name
     * would not match the filename the migrator derives it from.
     */
    private ?string $timestamp = null;

    /**
     * The description, normalized to snake_case.
     */
    private string $description = '';

    public function getName(): string
    {
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' make:migration';
    }

    public function getDescription(): string
    {
        return 'Create a new database migration file.';
    }

    protected function getType(): string
    {
        return 'Migration';
    }

    /**
     * Migrations live in a namespace of their own, matching where they are
     * written, so the generated file is loadable by the project's autoloader as
     * well as by the migrator's own `require`.
     */
    protected function getNamespace(): string
    {
        return (string) config('app.namespace', Config::DEFAULT_NAMESPACE) . '\\Database\\Migrations';
    }

    protected function getStubPath(): string
    {
        return base_path('stubs/migration.stub');
    }

    /**
     * The description is normalized before the base class validates it, so
     * `make:migration "Create Leads Table"` and `make:migration create_leads_table`
     * produce the same file rather than the first being rejected.
     */
    protected function resolveClassName(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $description = $this->snake($name);

        // A name made only of separators — "!!!", "---", "   " — normalizes to an
        // empty description, which would name the file `<timestamp>_.php`. That
        // does not match MigrationFile::PATTERN, so the migrator would skip it
        // forever while the generator reported success: a migration that silently
        // never exists. Rejected here instead, where it costs one message.
        if ($description === '') {
            return null;
        }

        $this->description = $description;
        $this->timestamp = date('Y_m_d_His');

        return MigrationFile::studly($this->description);
    }

    protected function getTargetPath(string $className): string
    {
        return $this->migrationsPath() . '/' . $this->timestamp() . '_' . $this->description . '.php';
    }

    /**
     * @return array<string, string>
     */
    protected function getReplacements(string $className): array
    {
        return [
            // `table` is the conventional guess at what the migration is for: a
            // create_*_table name yields that table, anything else yields an
            // empty string so the stub does not invent a wrong one.
            '{{ table }}' => $this->guessTable(),
            // The stub's docblock names the command that produced it, which is
            // the CLI prefix as configured rather than this framework's default.
            '{{ cli_prefix }}' => (string) config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX),
        ];
    }

    protected function getPostWriteHint(): ?string
    {
        return 'Implement up() and down(), then run: wp ' . config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' migrate';
    }

    /**
     * The argument is a description, not a class name, so the base class's
     * "…make:migration Blog" example would generate a migration called Blog.
     */
    protected function missingNameMessage(): string
    {
        return sprintf(
            'Please provide a migration description: %s create_leads_table',
            $this->getName()
        );
    }

    /**
     * The directory migrations are discovered in.
     */
    private function migrationsPath(): string
    {
        if (!function_exists('base_path')) {
            throw new RuntimeException('base_path() is unavailable; cannot locate the migrations directory.');
        }

        return base_path('database/migrations');
    }

    private function timestamp(): string
    {
        return $this->timestamp ??= date('Y_m_d_His');
    }

    /**
     * Turn free-form input into snake_case.
     *
     * Accepts what a developer is likely to type — spaces, dashes, camelCase,
     * mixed case — rather than demanding they pre-format it, since a rejected
     * generator invocation is pure friction.
     */
    private function snake(string $name): string
    {
        $value = str_replace(['-', ' '], '_', trim($name));

        // A camelCase or PascalCase boundary becomes an underscore, so
        // `createLeadsTable` and `CreateLeadsTable` both become
        // `create_leads_table`.
        $value = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value);
        $value = (string) preg_replace('/[^A-Za-z0-9_]+/', '_', $value);
        $value = (string) preg_replace('/_+/', '_', $value);

        return strtolower(trim($value, '_'));
    }

    /**
     * The table a `create_*_table` / `add_*_to_*` migration is about.
     */
    private function guessTable(): string
    {
        if (preg_match('/^create_(.+)_table$/', $this->description, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/^(?:add|drop|remove)_.+_(?:to|from)_(.+)$/', $this->description, $m) === 1) {
            return $m[1];
        }

        return '';
    }
}
