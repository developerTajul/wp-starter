<?php
declare(strict_types=1);

namespace Tests\Unit\Console;

use Framework\Config\Config;
use Framework\Console\Commands\MakeCommandCommand;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

/**
 * `make:command`, which is what finally makes `App\Console\Command` reachable.
 *
 * Two things are being pinned, and they are different kinds of claim.
 *
 * The **file** claim is that the shipped stub renders into a class that extends
 * the application's own base command — not the framework's — because that base
 * class exists precisely so project-wide output conventions have a home in the
 * user's namespace, and a generator that skipped it would leave it as dead as it
 * was before.
 *
 * The **name** claim is the interesting one: the command name is *derived* from
 * the class name rather than asked for, so the derivation is what decides whether
 * `SendDigestCommand` registers as `send-digest` or as something nobody can type.
 */
final class MakeCommandCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->workspace = sys_get_temp_dir() . '/wpml-makecmd-' . bin2hex(random_bytes(6));
        mkdir($this->workspace . '/app/Console/Commands', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
    }

    public function test_it_writes_a_command_class_into_app_console_commands(): void
    {
        $command = $this->command();
        $command->handle(['SendDigest'], []);

        $path = $this->workspace . '/app/Console/Commands/SendDigest.php';

        $this->assertFileExists($path);
        $this->assertStringContainsString('Command created successfully', implode("\n", $command->messages));
    }

    /**
     * The whole point of the generator: the generated class extends the
     * *application's* base command, so the layer is no longer unreachable.
     */
    public function test_the_generated_class_extends_the_application_command(): void
    {
        $this->command()->handle(['SendDigest'], []);

        $contents = (string) file_get_contents($this->workspace . '/app/Console/Commands/SendDigest.php');

        $this->assertStringContainsString('use App\\Console\\Command;', $contents);
        $this->assertStringContainsString('extends Command', $contents);
        $this->assertStringNotContainsString('extends \Framework\Console\Command', $contents);
    }

    /**
     * The generated file must implement the abstract surface, or it is a class
     * that fatals the moment it is autoloaded.
     */
    public function test_the_generated_class_implements_the_command_contract(): void
    {
        $this->command()->handle(['SendDigest'], []);

        $contents = (string) file_get_contents($this->workspace . '/app/Console/Commands/SendDigest.php');

        $this->assertStringContainsString('public function getName(): string', $contents);
        $this->assertStringContainsString('public function getDescription(): string', $contents);
        $this->assertStringContainsString('public function handle(array $args, array $assocArgs): void', $contents);
    }

    /**
     * No placeholder may survive — a literal `{{ command }}` inside the
     * `getName()` return would register a command called `{{ command }}`.
     *
     * This caught a real one: the stub's docblock named `{{ cli_prefix }}`, which
     * `getReplacements()` did not supply, so it rendered verbatim. The base class
     * only ever substitutes `namespace` and `class`, so *any* other placeholder in
     * a stub has to be provided by the generator or it ships as literal text.
     */
    public function test_the_stub_leaves_no_placeholders_behind(): void
    {
        $this->command()->handle(['SendDigest'], []);

        $this->assertDoesNotMatchRegularExpression(
            '/\{\{\s*\w+\s*\}\}/',
            (string) file_get_contents($this->workspace . '/app/Console/Commands/SendDigest.php')
        );
    }

    /**
     * The generated class must be loadable and instantiable, and its own
     * `getName()` must return the prefixed verb — the stub is only correct if the
     * class it produces actually works, which rendering it as a string cannot
     * show.
     */
    public function test_the_generated_command_is_runnable_and_registers_its_name(): void
    {
        $this->command()->handle(['SendDigest'], []);

        $path = $this->workspace . '/app/Console/Commands/SendDigest.php';
        $source = (string) file_get_contents($path);

        // The stub extends `App\Console\Command`, which is not autoloadable from a
        // temp directory, so the base class is rewritten for the duration of this
        // check. Everything else — the namespace, the config call, the `handle()`
        // signature — is the stub's own.
        $source = str_replace('extends Command', 'extends \\Framework\\Console\\Command', $source);
        $source = str_replace('use App\\Console\\Command;', '', $source);
        $source = str_replace(
            'namespace ' . $this->namespaceOf($source) . ';',
            'namespace Tests\\Unit\\Console\\GeneratedCommand;',
            $source
        );
        $source = str_replace('final class SendDigest', 'final class RunnableSendDigest', $source);

        file_put_contents($path, $source);
        require_once $path;

        $class = 'Tests\\Unit\\Console\\GeneratedCommand\\RunnableSendDigest';
        $this->assertTrue(class_exists($class), 'The generated command did not declare a loadable class.');

        /** @var \Framework\Console\Command $instance */
        $instance = new $class();

        $prefix = config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX);

        $this->assertSame("{$prefix} send-digest", $instance->getName());
        $this->assertNotSame('', $instance->getDescription());

        // Runs without WP_CLI present: the base class's helpers are no-ops there,
        // which is the "runnable outside WP-CLI" path the framework promises.
        $instance->handle([], []);
    }

    private function namespaceOf(string $source): string
    {
        preg_match('/^namespace\s+([^;]+);/m', $source, $m);

        return $m[1] ?? '';
    }

    /**
     * The class name the caller types is the class name on disk. A suffix is not
     * appended, because the caller decides whether they want one — and the
     * command *name* strips it either way, which the cases below pin.
     */
    public function test_the_class_keeps_the_name_the_caller_gave(): void
    {
        $this->command()->handle(['SendDigest'], []);

        $this->assertFileExists($this->workspace . '/app/Console/Commands/SendDigest.php');
        $this->assertFileDoesNotExist($this->workspace . '/app/Console/Commands/SendDigestCommand.php');
    }

    /**
     * The WP-CLI verb derived from the class name.
     *
     * @dataProvider commandNames
     */
    public function test_the_command_name_is_derived_from_the_class(string $class, string $expectedVerb): void
    {
        $this->command()->handle([$class], []);

        $contents = (string) file_get_contents($this->workspace . '/app/Console/Commands/' . $class . '.php');

        $this->assertStringContainsString(
            "' " . $expectedVerb . "'",
            $contents,
            "Class [{$class}] should register the command verb [{$expectedVerb}]."
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function commandNames(): array
    {
        return [
            // A bare name is used as-is.
            'bare' => ['SendDigest', 'send-digest'],
            // The conventional suffix is stripped, so both spellings agree.
            'suffixed' => ['SendDigestCommand', 'send-digest'],
            // An acronym run stays together. Splitting per capital would give
            // `sync-h-t-t-p-cache`, which is unreadable and was the actual bug
            // in the first cut of the derivation.
            'acronym' => ['SyncHTTPCache', 'sync-http-cache'],
            'leading acronym' => ['HTTPDigest', 'http-digest'],
            // A single word needs no separator at all.
            'single word' => ['Digest', 'digest'],
            // Digits are not boundaries.
            'digits' => ['ImportV2Leads', 'import-v2-leads'],
        ];
    }

    /**
     * The suffix is stripped but never allowed to empty the name: a class called
     * literally `Command` would otherwise register the CLI prefix as a command,
     * colliding with every sibling under it.
     */
    public function test_a_class_named_command_falls_back_to_a_usable_verb(): void
    {
        $this->command()->handle(['Command'], []);

        $contents = (string) file_get_contents($this->workspace . '/app/Console/Commands/Command.php');

        $this->assertStringContainsString("' command'", $contents);
        $this->assertStringNotContainsString("cli_prefix', \\Framework\\Config\\Config::DEFAULT_CLI_PREFIX) . ' '", $contents);
    }

    /**
     * The *registered command name* is built from config at call time, not
     * hardcoded — so a rebranded project's commands stay grouped under its own
     * prefix without every generated file needing to be rewritten.
     *
     * Only the `getName()` line is asserted, not the whole file. The stub's
     * docblock legitimately carries the current prefix (it names the command that
     * produced the file), so a "the file never says wp_mini" assertion would be
     * testing the docblock rather than the behaviour that matters.
     */
    public function test_the_registered_name_reads_the_cli_prefix_from_config(): void
    {
        $this->command()->handle(['SendDigest'], []);

        $contents = (string) file_get_contents($this->workspace . '/app/Console/Commands/SendDigest.php');

        preg_match('/public function getName\(\).*?\n    \}/s', $contents, $m);

        $this->assertNotEmpty($m, 'The stub must declare getName().');
        $this->assertStringContainsString("config('app.cli_prefix'", $m[0]);
        $this->assertStringNotContainsString('wp_mini', $m[0]);
    }

    public function test_it_names_the_kernel_array_in_the_registration_hint(): void
    {
        $command = $this->command();
        $command->handle(['SendDigest'], []);

        $this->assertStringContainsString('App\\Console\\Kernel', implode("\n", $command->messages));
    }

    public function test_a_missing_name_is_reported(): void
    {
        $command = $this->command();
        $command->handle([], []);

        $this->assertStringContainsString('Please provide a command name', implode("\n", $command->messages));
    }

    public function test_it_refuses_to_overwrite_an_existing_command(): void
    {
        file_put_contents($this->workspace . '/app/Console/Commands/SendDigest.php', 'hand written');

        $command = $this->command();
        $command->handle(['SendDigest'], []);

        $this->assertSame(
            'hand written',
            file_get_contents($this->workspace . '/app/Console/Commands/SendDigest.php')
        );
        $this->assertStringContainsString('already exists', implode("\n", $command->messages));
    }

    public function test_the_command_name_is_prefixed(): void
    {
        $prefix = config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX);

        $this->assertSame("{$prefix} make:command", (new MakeCommandCommand())->getName());
    }

    /**
     * @return TestableMakeCommandCommand
     */
    private function command(): TestableMakeCommandCommand
    {
        return new TestableMakeCommandCommand($this->workspace);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }

        rmdir($path);
    }
}

/**
 * The real generator, aimed at a workspace, with its output captured.
 *
 * `projectPath()` is overridden rather than `path.base` being rebound, so the
 * command's own stub lookup and its target path both move together — and so the
 * test does not leave a global container binding mutated for whatever runs next.
 */
final class TestableMakeCommandCommand extends MakeCommandCommand
{
    /** @var list<string> */
    public array $messages = [];

    public function __construct(private readonly string $workspace)
    {
    }

    protected function projectPath(): string
    {
        return $this->workspace;
    }

    protected function getTargetPath(string $className): string
    {
        return $this->workspace . '/app/Console/Commands/' . $className . '.php';
    }

    protected function success(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function error(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function info(string $message): void
    {
        $this->messages[] = $message;
    }
}
