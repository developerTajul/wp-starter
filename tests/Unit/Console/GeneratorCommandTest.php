<?php
declare(strict_types=1);

namespace Tests\Unit\Console;

use Framework\Console\Command;
use Framework\Console\GeneratorCommand;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

/**
 * Covers the shared scaffolding behaviour every `make:*` command inherits.
 *
 * Driven through a subclass pointing at a temporary directory, so the suite
 * never writes into the plugin's own `app/` tree.
 */
final class GeneratorCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->workspace = sys_get_temp_dir() . '/wpml-generator-' . bin2hex(random_bytes(6));

        if (!is_dir($this->workspace . '/stubs')) {
            mkdir($this->workspace . '/stubs', 0755, true);
        }

        file_put_contents(
            $this->workspace . '/stubs/thing.stub',
            "<?php\n\nnamespace {{ namespace }};\n\nclass {{ class }}\n{\n    // {{ type }}\n}\n"
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
    }

    public function test_it_renders_the_stub_namespace_and_class(): void
    {
        $generator = $this->generator();

        $generator->handle(['Blog'], []);

        $path = $this->workspace . '/out/Blog.php';
        $this->assertFileExists($path);

        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('namespace Tests\Unit\Console;', $contents);
        $this->assertStringContainsString('class Blog', $contents);
    }

    public function test_it_substitutes_type_specific_placeholders(): void
    {
        $this->generator()->handle(['Blog'], []);

        $this->assertStringContainsString('// generator', (string) file_get_contents($this->workspace . '/out/Blog.php'));
    }

    /**
     * A directory that does not exist yet is the normal case for the first
     * class in a new namespace level, so it must be created rather than
     * reported as a failure.
     */
    public function test_it_creates_the_target_directory(): void
    {
        $this->assertDirectoryDoesNotExist($this->workspace . '/out');

        $this->generator()->handle(['Blog'], []);

        $this->assertDirectoryExists($this->workspace . '/out');
    }

    /**
     * Overwriting an existing class would silently destroy hand-written work,
     * so the generator refuses and says which file it refused to touch.
     */
    public function test_it_refuses_to_overwrite_an_existing_file(): void
    {
        mkdir($this->workspace . '/out', 0755, true);
        file_put_contents($this->workspace . '/out/Blog.php', 'hand written');

        $generator = $this->generator();
        $generator->handle(['Blog'], []);

        $this->assertSame('hand written', file_get_contents($this->workspace . '/out/Blog.php'));
        $this->assertStringContainsString('already exists', $generator->messages[0]);
    }

    /**
     * Path separators and a .php suffix are stripped rather than rejected: a
     * caller writing `Providers/Blog.php` means Blog, and failing on it would
     * be pedantry rather than protection.
     */
    public function test_it_strips_path_separators_and_a_php_suffix_from_the_name(): void
    {
        $this->generator()->handle(['Nested/Blog.php'], []);

        $this->assertFileExists($this->workspace . '/out/NestedBlog.php');
    }

    /**
     * Backslashes are the namespace spelling of the same thing and must not
     * escape into the file name.
     */
    public function test_it_strips_backslashes_from_the_name(): void
    {
        $this->generator()->handle(['Nested\\Blog'], []);

        $this->assertFileExists($this->workspace . '/out/NestedBlog.php');
    }

    /**
     * A name that cannot be a class name must be reported, not written — a file
     * containing an invalid class is far harder to diagnose than a refusal.
     */
    public function test_it_rejects_a_name_that_cannot_be_a_class(): void
    {
        $generator = $this->generator();
        $generator->handle(['9-Blog!'], []);

        $this->assertStringContainsString('Invalid', $generator->messages[0]);
        $this->assertFileDoesNotExist($this->workspace . '/out/9Blog.php');
    }

    public function test_a_missing_name_is_reported_with_an_example(): void
    {
        $generator = $this->generator();
        $generator->handle([], []);

        $this->assertStringContainsString('Please provide a generator name', $generator->messages[0]);
        $this->assertStringContainsString('make:thing', $generator->messages[0]);
    }

    public function test_an_empty_name_is_treated_as_missing(): void
    {
        $generator = $this->generator();
        $generator->handle(['   '], []);

        $this->assertStringContainsString('Please provide a generator name', $generator->messages[0]);
    }

    /**
     * A stub that has gone missing is a packaging bug. It must be reported
     * rather than producing an empty file.
     */
    public function test_a_missing_stub_is_reported(): void
    {
        $generator = $this->generator(stub: 'stubs/absent.stub');
        $generator->handle(['Blog'], []);

        $this->assertStringContainsString('Stub file not found', $generator->messages[0]);
        $this->assertFileDoesNotExist($this->workspace . '/out/Blog.php');
    }

    public function test_a_post_write_hint_is_emitted_after_success(): void
    {
        $generator = $this->generator();
        $generator->handle(['Blog'], []);

        $this->assertStringContainsString('Remember to register it.', end($generator->messages));
    }

    private function generator(string $stub = 'stubs/thing.stub'): TestableGenerator
    {
        return new TestableGenerator($this->workspace, $stub);
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
 * A concrete generator over a throwaway workspace.
 *
 * Output is recorded instead of printed because the WP-CLI stubs in this suite
 * deliberately do not define WP_CLI — the base Command's helpers are then no-ops,
 * which is exactly the "runnable outside WP-CLI" path the framework promises.
 */
final class TestableGenerator extends GeneratorCommand
{
    /** @var list<string> */
    public array $messages = [];

    public function __construct(
        private readonly string $workspace,
        private readonly string $stub
    ) {
    }

    public function getName(): string
    {
        return 'wp_mini make:thing';
    }

    public function getDescription(): string
    {
        return 'Create a thing.';
    }

    protected function getType(): string
    {
        return 'generator';
    }

    protected function getStubPath(): string
    {
        return $this->workspace . '/' . $this->stub;
    }

    protected function getTargetPath(string $className): string
    {
        return $this->workspace . '/out/' . $className . '.php';
    }

    protected function getNamespace(): string
    {
        return 'Tests\Unit\Console';
    }

    protected function getReplacements(string $className): array
    {
        return ['{{ type }}' => 'generator'];
    }

    protected function getPostWriteHint(): ?string
    {
        return 'Remember to register it.';
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
