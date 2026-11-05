<?php
declare(strict_types=1);

namespace Tests\Unit\Support;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Http\Requests\FormRequest;
use Framework\Http\HttpServiceProvider;
use Framework\Http\Responses\ViewResponse;
use Framework\Support\ViewRenderer;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\FlashStore;
use Tests\Support\WpStub;

/**
 * The view helpers in framework/Support/helpers.php, and the renderer's binding.
 *
 * ## Why so much of this runs in a separate process
 *
 * `app()` holds the container in a *function-level static*, which lives for the
 * whole PHP process and cannot be reset. A test that binds into it, or that
 * resolves a singleton, therefore leaks into every test after it — and the
 * failure shows up as an unrelated test seeing a renderer that already has a
 * root pointed at a deleted temp directory. Separate processes are the only
 * isolation available, so tests touching the container use it.
 *
 * The escaping tests do not, because `e()` reads no container state at all.
 */
final class ViewHelperTest extends TestCase
{
    protected function setUp(): void
    {
        WpStub::reset();
    }

    // -------------------------------------------------------------------- e()

    public function test_a_string_is_escaped(): void
    {
        $this->assertSame('&lt;script&gt;', e('<script>'));
    }

    /**
     * Both quote styles, deliberately. A value interpolated into an attribute is
     * escaped for a *different* context than one in text, and `ENT_QUOTES` is the
     * only mode correct in both: without it, `value="<?= e($x) ?>"` lets an
     * apostrophe close the attribute and inject a handler — which is the exact
     * shape of the bug this helper exists to prevent.
     */
    public function test_quotes_are_escaped_for_an_attribute_context(): void
    {
        $this->assertSame('&quot; onmouseover=&quot;x', e('" onmouseover="x'));
        $this->assertSame('&#039; onmouseover=&#039;x', e("' onmouseover='x"));
    }

    public function test_an_ampersand_is_escaped_once(): void
    {
        $this->assertSame('a &amp; b', e('a & b'));
    }

    public function test_a_non_ascii_string_survives_escaping(): void
    {
        $this->assertSame('Tájul &amp; Co', e('Tájul & Co'));
    }

    /**
     * An optional field is the overwhelmingly common case, and `value="null"` in
     * a form is never what was meant. Rendering it empty is the only sane answer.
     */
    public function test_null_renders_empty(): void
    {
        $this->assertSame('', e(null));
    }

    /**
     * A bool has no sensible string form. `false` rendering as `""` is right;
     * `true` as `"1"` matches what a checkbox actually submits, which is what a
     * template re-rendering a checked box needs.
     */
    public function test_a_bool_renders_as_a_form_value(): void
    {
        $this->assertSame('1', e(true));
        $this->assertSame('', e(false));
    }

    /**
     * An array reaching a template is nearly always an accident, and PHP's own
     * `"Array"` is the worst possible answer — it is valid-looking text that
     * silently renders in the page. A JSON document at least shows the value.
     */
    public function test_an_array_renders_as_json_rather_than_the_word_array(): void
    {
        $this->assertSame('{&quot;a&quot;:1}', e(['a' => 1]));
        $this->assertStringNotContainsString('Array', e(['a' => 1]));
    }

    public function test_an_integer_renders_as_its_digits(): void
    {
        $this->assertSame('42', e(42));
    }

    // ------------------------------------------------------------- the helpers

    /**
     * `view()` returns a response rather than a string, because a controller
     * returns it — and because a caller that wants the markup can say so with
     * `->render()`, which reads as the deliberate act it is.
     */
    #[RunInSeparateProcess]
    public function test_view_returns_a_response(): void
    {
        $this->bindRenderer('<p><?= $name ?></p>', 'page', ['name' => 'Ada']);

        $response = view('page');

        $this->assertInstanceOf(ViewResponse::class, $response);
        $this->assertSame('<p>Ada</p>', $response->render());
    }

    /**
     * `old()` escapes as well as reads, because the pair is what a form field
     * actually needs and writing `e(...->oldValue(...))` in every input is how a
     * project ends up with one input that forgot to.
     */
    #[RunInSeparateProcess]
    public function test_old_reads_and_escapes_a_flashed_value(): void
    {
        $this->bindRenderer('ignored', 'page', [], ['old' => ['email' => 'a"b@example.test']]);

        $this->assertSame('a&quot;b@example.test', old('email'));
    }

    public function test_old_falls_back_when_nothing_was_flashed(): void
    {
        $this->assertSame('default', old('email', 'default'));
        $this->assertSame('', old('email'));
    }

    /**
     * Outside WordPress — and before the application has booted — `old()` must
     * still be callable. A template that fatal-errors because the container has
     * no renderer is worse than one that renders an empty value, and a helper
     * reaching into the container is exactly where that would come from.
     */
    #[RunInSeparateProcess]
    public function test_old_returns_the_default_without_a_renderer(): void
    {
        app()->forget(ViewRenderer::class);

        $this->assertSame('fallback', old('email', 'fallback'));
    }

    // ------------------------------------------------------------- the binding

    /**
     * The renderer is bound as a **singleton**, and that is a correctness
     * requirement rather than a caching choice: it holds the shared variable bag
     * a project fills in `boot()`, and it *consumes* the flash transient on first
     * read. A per-use renderer would mean a project's `share()` calls vanished,
     * and a page rendering two partials would read the flash twice — the second
     * read empty, so the errors appear in one of them.
     */
    #[RunInSeparateProcess]
    public function test_the_provider_binds_the_renderer_as_a_singleton(): void
    {
        $container = new Container();
        (new HttpServiceProvider($container))->register();

        $first = $container->resolve(ViewRenderer::class);
        $second = $container->resolve(ViewRenderer::class);

        $this->assertInstanceOf(ViewRenderer::class, $first);
        $this->assertSame($first, $second);
    }

    /**
     * `ViewRenderer`'s constructor takes an error-bag class used as a fallback
     * source of flash data, and the container builds it with no arguments. If that
     * parameter ever loses its default the binding stops resolving — a failure
     * that would only appear at the first render, in production.
     */
    #[RunInSeparateProcess]
    public function test_the_renderer_is_resolvable_without_arguments(): void
    {
        $container = new Container();
        (new HttpServiceProvider($container))->register();

        $this->assertInstanceOf(ViewRenderer::class, $container->resolve(ViewRenderer::class));
    }

    // -------------------------------------------------------------------- helpers

    /**
     * Point a bound renderer at a temp root holding one view, and set up a flash
     * payload through the transient the redirect would have written.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $flash
     */
    private function bindRenderer(
        string $contents,
        string $name,
        array $data = [],
        array $flash = []
    ): void {
        $root = sys_get_temp_dir() . '/wp-mini-laravel-helper-' . bin2hex(random_bytes(4));
        mkdir($root, 0755, true);
        file_put_contents($root . '/' . $name . '.php', $contents);

        // Cleaned at shutdown rather than in tearDown: a separate-process test's
        // tearDown runs before the child exits, and the renderer still holds the
        // root — an rmdir here would race the assertion that is about to happen.
        register_shutdown_function(static function () use ($root): void {
            foreach (glob($root . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($root);
        });

        $renderer = (new ViewRenderer())->setRoot($root)->shareMany($data);

        app()->instance(ViewRenderer::class, $renderer);

        if ($flash !== []) {
            FlashStore::write($flash);
        }
    }
}
