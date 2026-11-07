<?php
declare(strict_types=1);

namespace Framework\Database\Commands;

use Framework\Config\Config;
use Framework\Console\GeneratorCommand;
use Framework\Database\MigrationFile;
use Framework\Support\ClassName;
use InvalidArgumentException;
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

    /**
     * The stub this run resolved to, remembered for the post-write hint.
     *
     * Set as a side effect of {@see getStubPath()}, which the base class calls
     * once during rendering; read afterwards to tailor the hint to the shape of
     * migration that was actually generated.
     */
    private string $stubName = 'migration.stub';

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
     * A migration's input is a description, not a class name.
     *
     * The default parse refuses a lower-case segment, because `admin/…` in
     * `make:controller` is ambiguous with the class name the caller also had to
     * type. There is no such ambiguity here: the whole string is prose that gets
     * snake_cased, so `notes/create_leads_table` is a legitimate request that the
     * strict rule would reject on the word `notes`.
     */
    protected function parseName(string $name): ClassName
    {
        return ClassName::parseLenient($name);
    }

    protected function getStubPath(): string
    {
        // Chosen by what the caller told us, never inferred from the description.
        // A `--table=`/`--create=` flag names the table outright, and the stub
        // that follows scaffolds against it; without one there is no table to
        // scaffold against, so the bare stub that scaffolds nothing is used.
        //
        // Bare names: StubLocator prefers the project's stubs/ copy and falls back
        // to the framework's, so a project can override any of the three and this
        // works from vendor/ too.
        if ($this->isCreating()) {
            $this->stubName = 'migration.create.stub';

            return $this->stubName;
        }

        if ($this->targetTable() !== null) {
            $this->stubName = 'migration.table.stub';

            return $this->stubName;
        }

        $this->stubName = 'migration.stub';

        return $this->stubName;
    }

    /**
     * Whether `--create` was given, meaning the migration makes a new table.
     *
     * The explicit counterpart to `--table`, and the one that decides between
     * `Schema::create()` and `Schema::table()`. Both flags may be given together
     * and should agree; when they do not, `--create` wins because it is the flag
     * that states an intent rather than naming a target, and a `CREATE` against a
     * table that already exists is the failure that announces itself immediately.
     */
    private function isCreating(): bool
    {
        return $this->flagValue('create') !== null;
    }

    /**
     * The table this migration targets, spelled by the caller or not at all.
     *
     * **Not guessed from the description.** A `create_leads_table` name used to be
     * read as naming `leads`, which produced a scaffold that looked right and was
     * right often enough to be trusted — and wrong the rest of the time, silently.
     * `create_leads_and_companies` guessed nothing, `add_status_to_orders` guessed
     * `orders` whether or not that was the intent, and a rename or a data migration
     * guessed from a preposition. A wrong table name in a scaffold is worse than an
     * empty one: it is the line a developer is least likely to re-read, and it is
     * a destructive statement waiting to be run.
     *
     * So the table comes from `--create=` or `--table=`, and from nowhere else.
     * Without either, the generated file scaffolds nothing and says so.
     */
    private function targetTable(): ?string
    {
        return $this->flagValue('table') ?? $this->flagValue('create');
    }

    /**
     * `--table=`/`--create=` name a table, so they have to be usable as one.
     *
     * Checked here rather than left to the database, because the failure it
     * prevents is not a crash: an invalid table name renders a valid-looking
     * migration that dies when it runs, long after the developer has stopped
     * looking at the generated file.
     */
    private function assertTableFlagIsUsable(): void
    {
        foreach (['table', 'create'] as $flag) {
            $value = $this->flagValue($flag);

            if ($value === null) {
                continue;
            }

            if (preg_match('/^[A-Za-z0-9_$]+$/', $value) !== 1) {
                throw new RuntimeException(sprintf(
                    'The --%s value [%s] is not a usable table name. A table name may only '
                    . 'contain letters, digits, underscores and dollar signs.',
                    $flag,
                    $value
                ));
            }
        }
    }

    /**
     * `migration.stub` uses only `{{ table }}` alongside the `{{ class }}` and
     * `{{ namespace }}` the base class supplies. `{{ cli_prefix }}` is a
     * `command.stub` placeholder — it is replaced by {@see MakeCommandCommand},
     * which renders a stub that names the command in its docblock. This command
     * renders no such stub, so the key was never read.
     *
     * `{{ table }}` is replaced in every stub, including the one that does not
     * mention it: `str_replace` over a key the stub lacks is a no-op, and emitting
     * an empty string for a stub that *does* mention it is what makes the
     * no-table case scaffold nothing rather than scaffold something wrong.
     *
     * @return array<string, string>
     */
    protected function getReplacements(string $className): array
    {
        return [
            '{{ table }}' => $this->targetTable() ?? '',
        ];
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

        // Refused before anything is written, and refusals are checked before the
        // name is even normalized: a bad `--table` is a mistake in the invocation,
        // not something to report against a file that was about to be created.
        $this->assertTableFlagIsUsable();

        // `parseName()` rejects a segment that is not a label at all, which is
        // correct but is not what a caller typing a *description* expects to hear
        // — they have not made a naming mistake, they have given prose where a
        // description belonged. Answered with the same "please provide a
        // description" message as no argument at all, which is the message that
        // tells them what to do next.
        //
        // `!!!` is the case that matters: it normalizes to an empty description,
        // which would name the file `<timestamp>_.php` — a filename
        // `MigrationFile::PATTERN` rejects, so the migrator would skip it forever
        // while the generator reported success. A migration that silently never
        // exists is the failure this refusal prevents.
        try {
            // Split off before normalization, because `notes/Create Notes Table`
            // has to keep its directory while the segment after it is snake_cased.
            $parsed = $this->parseName($name);
        } catch (InvalidArgumentException) {
            return null;
        }

        // A sub-namespace segment is a namespace, so it is StudlyCased like the
        // class — `notes/create_notes_table` declares
        // `App\Database\Migrations\Notes`, matching every other namespace in the
        // project. The description itself is snake_cased below instead, because
        // it becomes a *filename*.
        //
        // `$parsed` is rebuilt rather than mutated: `ClassName` is readonly, and
        // rebuilding is what keeps the directory and the namespace from being
        // normalized by different rules.
        // `parseLenient()`, not `parse()`: the description segment is snake_case
        // prose and is about to become a filename, so the strict upper-case rule
        // does not apply to it — only to the sub-namespace segment, which
        // `studly()` has just capitalised.
        if ($parsed->sub !== '') {
            $sub = implode('/', array_map(
                static fn (string $segment): string => MigrationFile::studly($segment),
                explode('\\', $parsed->sub)
            ));

            $parsed = ClassName::parseLenient($sub . '/' . $parsed->class);
        }

        $description = $this->snake($parsed->class);

        if ($description === '') {
            return null;
        }

        $this->parsed = $parsed;
        $this->description = $description;
        $this->timestamp = date('Y_m_d_His');

        return MigrationFile::studly($this->description);
    }

    protected function getTargetPath(string $className): string
    {
        // The sub-directory comes from the parsed name, so
        // `notes/create_notes_table` lands in `database/migrations/notes/` —
        // matching the `...\Migrations\Notes` namespace the base class renders.
        // The filename itself stays `<timestamp>_<snake_case_description>`,
        // because that is the shape `MigrationFile::PATTERN` requires and the
        // migrator parses: the directory is organization, the basename is
        // identity.
        return $this->migrationsPath()
            . '/' . $this->subDirectoryPath()
            . $this->timestamp() . '_' . $this->description . '.php';
    }

    /**
     * Migrations live in a namespace of their own, matching where they are
     * written, so the generated file is loadable by the project's autoloader as
     * well as by the migrator's own `require`.
     *
     * The caller's sub-namespace is appended by the base class, not here — see
     * {@see GeneratorCommand::namespaceFor()}. Appending it in both places was a
     * real bug: the rendered namespace came out as `...Migrations\notes\notes`.
     */
    protected function getNamespace(): string
    {
        return (string) config('app.namespace', Config::DEFAULT_NAMESPACE) . '\\Database\\Migrations';
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
}
