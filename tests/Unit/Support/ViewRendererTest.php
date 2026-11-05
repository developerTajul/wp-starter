<?php
declare(strict_types=1);

namespace Tests\Unit\Support;

use Framework\Http\Exceptions\HttpException;
use Framework\Http\Responses\HtmlResponse;
use Framework\Http\Responses\ViewResponse;
use Framework\Support\ViewRenderer;
use PHPUnit\Framework\TestCase;
use Tests\Support\FlashStore;
use Tests\Support\WpStub;

/**
 * The view layer: name resolution, variable layering, and the flash bridge.
 *
 * ## What is actually worth pinning here
 *
 * Rendering a PHP file is not interesting and PHPUnit would not be testing
 * anything. The three things that *are* worth pinning are the ones that fail
 * silently in production:
 *
 *  - **Precedence.** Shared data, flashed data and an action's own `$data` are
 *    merged into one variable set. Get the order wrong and an action cannot
 *    override a shared value, or a shared value quietly beats a flashed error.
 *    Nothing throws; a form just shows the wrong thing.
 *  - **One flash read per instance.** `readFlash()` consumes the transient. A
 *    second uncached read returns empty, so a page rendering two partials shows
 *    the errors in one of them — and works fine when tested with one partial.
 *  - **`..` is refused, not normalized.** A view name is data in the general
 *    case, and a name that climbs out of the root is a file read the caller did
 *    not intend.
 *
 * The renderer is constructed directly rather than resolved from the container,
 * so these tests prove the class's own behaviour. {@see ViewProviderTest} covers
 * the binding.
 */
final class ViewRendererTest extends TestCase
{
    private string $viewDir;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->viewDir = sys_get_temp_dir() . '/wp-mini-laravel-views-' . bin2hex(random_bytes(4));
        mkdir($this->viewDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->viewDir);
    }

    /**
     * Remove a directory and everything under it.
     *
     * Recursive rather than the flat "glob files, then rmdir" the older response
     * tests use, because these views are nested — `leads.index` writes a
     * *directory*, and a teardown that only knew about files leaves a temp
     * directory behind on every run and a warning on every assertion that follows.
     */
    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                $this->removeTree($entry);

                continue;
            }

            unlink($entry);
        }

        rmdir($directory);
    }

    // ------------------------------------------------------------- name resolution

    /**
     * The dot-to-directory mapping is the whole reason a controller can name a
     * view instead of building a path, so it is pinned in both spellings a
     * project might use.
     */
    public function test_a_dotted_name_resolves_to_a_nested_php_file(): void
    {
        $this->write('leads/index.php', 'leads');

        $renderer = $this->renderer();

        $this->assertStringEndsWith('leads/index.php', $renderer->path('leads.index'));
        $this->assertSame('leads', $renderer->render('leads.index'));
    }

    public function test_a_slashed_name_resolves_too(): void
    {
        $this->write('leads/index.php', 'leads');

        $this->assertSame('leads', $this->renderer()->render('leads/index'));
    }

    /**
     * A caller holding a path from elsewhere should not have to strip the
     * extension before the renderer will accept it.
     */
    public function test_a_name_that_already_ends_in_php_is_left_alone(): void
    {
        $this->write('plain.php', 'plain');

        $this->assertSame('plain', $this->renderer()->render('plain.php'));
    }

    /**
     * A missing view must name the *view*, not the path. The path is a file the
     * caller never wrote a name for, and "leads/indx.php is missing" sends the
     * reader looking for a file rather than at the typo in their action.
     */
    public function test_a_missing_view_throws(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('does not exist');

        $this->renderer()->path('leads.indx');
    }

    public function test_exists_answers_without_rendering(): void
    {
        $this->write('leads/index.php', '<?php throw new \RuntimeException("should not render"); ?>');

        $renderer = $this->renderer();

        $this->assertTrue($renderer->exists('leads.index'));
        $this->assertFalse($renderer->exists('leads.missing'));
    }

    // ------------------------------------------------------------------- escaping

    /**
     * A view name routinely comes from data — a settings screen storing "which
     * template", a template-override table — so a parent segment is a file read
     * the caller did not intend. Refusing names the mistake; normalizing would
     * silently load *something*.
     */
    public function test_a_name_that_climbs_out_of_the_root_is_refused(): void
    {
        file_put_contents(dirname($this->viewDir) . '/wp-mini-laravel-secret.php', 'secret');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('outside the view root');

        try {
            $this->renderer()->path('../wp-mini-laravel-secret');
        } finally {
            @unlink(dirname($this->viewDir) . '/wp-mini-laravel-secret.php');
        }
    }

    /**
     * The check has to happen on the raw name. `....` looks like a harmless typo
     * and expands into two parent segments once dots become separators — an
     * escape that a check performed after substitution would already have
     * performed the substitution to find.
     */
    public function test_dots_that_would_become_a_parent_segment_are_refused(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('outside the view root');

        $this->renderer()->path('....etc.passwd');
    }

    /**
     * `exists()` must answer false rather than throw for an escaping name. Its
     * contract is "can I render this", and a template-override chain asking about
     * each candidate in turn would otherwise abort on the first unusable one.
     */
    public function test_exists_reports_false_for_a_name_that_escapes(): void
    {
        $this->assertFalse($this->renderer()->exists('../../etc/passwd'));
    }

    /**
     * The dotted convention and the extension spelling have to name the same
     * file, or a config value written as `leads.index.php` would 404 while
     * `leads.index` worked — and nothing would say why.
     */
    public function test_a_name_ending_in_php_names_the_same_file(): void
    {
        $path = $this->write('leads/index.php', 'leads');

        $renderer = $this->renderer();

        $this->assertSame($path, $renderer->path('leads.index'));
        $this->assertSame($path, $renderer->path('leads.index.php'));
        $this->assertSame('leads', $renderer->render('leads.index.php'));
    }

    /**
     * And the literal reading. `receipt.v2.php` is a real filename whose dots are
     * *not* separators, so the dotted reading misses and the name has to be tried
     * verbatim — otherwise the file is unreachable under the name it actually has.
     */
    public function test_a_dotted_filename_is_reachable_literally(): void
    {
        $this->write('receipt.v2.php', 'receipt');

        $this->assertSame('receipt', $this->renderer()->render('receipt.v2.php'));
    }

    /**
     * The literal fallback must not shadow the convention. When both readings
     * could exist, the dotted one wins — otherwise adding a file named
     * `leads.index.php` next to `leads/index.php` would silently change which
     * template renders.
     */
    public function test_the_dotted_reading_wins_over_the_literal_one(): void
    {
        $nested = $this->write('leads/index.php', 'dotted');
        $this->write('leads.index.php', 'literal');

        $this->assertSame($nested, $this->renderer()->path('leads.index.php'));
        $this->assertSame('dotted', $this->renderer()->render('leads.index.php'));
    }

    /**
     * The documented limitation of the dotted convention: a directory whose name
     * contains a dot is not addressable by a *name*, because the dot in that
     * segment is substituted like every other dot.
     *
     * Pinned deliberately rather than left unstated. This is the behaviour
     * someone will eventually report as a bug, and a failing assertion that
     * states the rule is a better answer than a rediscovery from a 404 page.
     */
    public function test_a_dotted_directory_is_not_reachable_by_name(): void
    {
        $this->write('v1.2/index.php', 'versioned');

        $renderer = $this->renderer();

        foreach (['v1.2.index', 'v1.2/index'] as $name) {
            try {
                $renderer->path($name);
                $this->fail(sprintf('View [%s] should not resolve.', $name));
            } catch (HttpException $e) {
                $this->assertStringContainsString('does not exist', $e->getMessage());
            }
        }
    }

    /**
     * The exception to that limitation, and the reason the literal fallback
     * earns its place: a name written *with* its extension finds the file, because
     * the dotted reading (`v1/2/index.php`) misses and the verbatim reading
     * (`v1.2/index.php`) does not.
     *
     * It is an escape hatch, not a fix — but a project with a `v1.2` directory has
     * one, and would otherwise have to rename it.
     */
    public function test_a_dotted_directory_is_reachable_when_spelled_with_the_extension(): void
    {
        $this->write('v1.2/index.php', 'versioned');

        $this->assertSame('versioned', $this->renderer()->render('v1.2/index.php'));
    }

    // -------------------------------------------------------------------- layering

    public function test_shared_data_reaches_a_view(): void
    {
        $this->write('greeting.php', '<?= $appName ?>');

        $renderer = $this->renderer()->share('appName', 'WP Mini Laravel');

        $this->assertSame('WP Mini Laravel', $renderer->render('greeting'));
    }

    /**
     * The precedence rule, and the one that actually breaks things when it is
     * backwards: an action that names a variable means it. A shared value
     * winning here would mean a project could set `appName` once and then never
     * override it on any page.
     */
    public function test_action_data_overrides_shared_data(): void
    {
        $this->write('greeting.php', '<?= $appName ?>');

        $renderer = $this->renderer()->share('appName', 'Shared');

        $this->assertSame('Named', $renderer->render('greeting', ['appName' => 'Named']));
    }

    public function test_action_data_overrides_a_flashed_value(): void
    {
        $this->write('notice.php', '<?= $notice ?? "none" ?>');

        FlashStore::write(['notice' => 'Flashed']);

        $this->assertSame(
            'Computed',
            $this->renderer()->render('notice', ['notice' => 'Computed'])
        );
    }

    public function test_nested_data_reaches_a_view(): void
    {
        $this->write('list.php', '<?= $leads[0]["name"] ?>');

        $this->assertSame(
            'Ada',
            $this->renderer()->render('list', ['leads' => [['name' => 'Ada']]])
        );
    }

    /**
     * Nothing flashed means no `$errors` variable at all, rather than an empty
     * array. A view that checks `isset($errors)` and one that checks
     * `$errors !== []` then agree, and neither has to guess what an empty
     * payload means.
     */
    public function test_no_flash_means_no_error_variables(): void
    {
        $this->write('bare.php', '<?= isset($errors) ? "has" : "none" ?>');

        $this->assertSame('none', $this->renderer()->render('bare'));
    }

    // ----------------------------------------------------------------- the flash

    public function test_errors_are_available_to_a_view(): void
    {
        $this->write('field.php', '<?= $errors["email"][0] ?>');

        FlashStore::write([
            'errors' => ['email' => ['Enter a valid email address.']],
        ]);

        $this->assertSame('Enter a valid email address.', $this->renderer()->render('field'));
    }

    public function test_old_input_is_available_to_a_view(): void
    {
        $this->write('input.php', '<?= $old["email"] ?>');

        FlashStore::write(['old' => ['email' => 'ada@example.test']]);

        $this->assertSame('ada@example.test', $this->renderer()->render('input'));
    }

    /**
     * The read is destructive — that is what a flash message *is* — but it has to
     * happen once per renderer rather than once per read. A page that renders a
     * header and a form both wanting the errors reads them twice; with an
     * uncached read the second comes back empty, and the form silently stops
     * showing its inline errors.
     */
    public function test_the_flash_is_read_once_for_several_reads(): void
    {
        $renderer = $this->renderer();

        FlashStore::write(['notice' => 'Saved']);

        $this->assertSame('Saved', $renderer->flashValue('notice'));
        $this->assertSame('Saved', $renderer->flashValue('notice'));
        $this->assertSame(['notice' => 'Saved'], $renderer->flash());
    }

    /**
     * Consumed, though — the transient is gone after the first read, so a refresh
     * does not resurrect a message the user has already seen. A renderer that
     * cached without consuming would show "Saved" forever.
     */
    public function test_reading_the_flash_consumes_the_transient(): void
    {
        FlashStore::write(['notice' => 'Saved']);

        $this->renderer()->flash();

        $this->assertTrue(
            FlashStore::consumed(),
            'A refresh must not resurrect a message the user has already seen.'
        );
    }

    /**
     * A hand-written `->with('errors', ['email' => 'Required'])` puts a string
     * where a list is expected, and it is a natural thing for a project to
     * write. Normalizing here is the difference between one line of defensive
     * code and one in every template.
     */
    public function test_a_single_error_string_is_normalized_to_a_list(): void
    {
        FlashStore::write(['errors' => ['email' => 'Required']]);

        $renderer = $this->renderer();

        $this->assertSame(['Required'], $renderer->errorsFor('email'));
        $this->assertSame('Required', $renderer->firstError('email'));
        $this->assertTrue($renderer->hasError('email'));
    }

    public function test_a_field_with_no_errors_reports_false(): void
    {
        FlashStore::write(['errors' => ['email' => ['Required']]]);

        $renderer = $this->renderer();

        $this->assertFalse($renderer->hasError('name'));
        $this->assertNull($renderer->firstError('name'));
        $this->assertSame([], $renderer->errorsFor('name'));
    }

    /**
     * `errors()` and `old()` share one read of the transient, so calling either
     * first must not starve the other.
     */
    public function test_errors_and_old_are_read_together(): void
    {
        FlashStore::write([
            'errors' => ['email' => ['Required']],
            'old' => ['email' => 'x'],
        ]);

        $renderer = $this->renderer();

        $this->assertSame('Required', $renderer->firstError('email'));
        $this->assertSame('x', $renderer->oldValue('email'));
    }

    /**
     * The summary line a notice above the form reads. It is flashed separately
     * from the per-field map because a view should not have to flatten the map
     * to say anything at all.
     */
    public function test_a_summary_error_is_available(): void
    {
        $this->write('summary.php', '<?= $error ?>');

        FlashStore::write(['error' => 'Please correct the errors below.']);

        $this->assertSame('Please correct the errors below.', $this->renderer()->render('summary'));
    }

    public function test_a_flash_value_defaults_when_absent(): void
    {
        $this->assertSame('fallback', $this->renderer()->flashValue('notice', 'fallback'));
    }

    /**
     * Outside WordPress — a WP-CLI export, a test — there is no transient store
     * to read. The renderer must render an empty flash rather than fail, or every
     * view becomes unrenderable the moment WordPress is not loaded.
     */
    public function test_no_flash_store_renders_empty(): void
    {
        $this->write('bare.php', '<?= count($old ?? []) ?>');

        $this->assertSame('0', $this->renderer()->render('bare'));
    }

    // ------------------------------------------------------------------- responses

    /**
     * `make()` must not render. A controller's return value has to be a value —
     * rendering at construction would make building a response a side effect,
     * and a view that throws would then throw while the controller was still
     * assembling its answer.
     */
    public function test_make_builds_a_response_without_rendering(): void
    {
        $path = $this->write('boom.php', '<?php throw new \RuntimeException("rendered too early"); ?>');

        $response = $this->renderer()->make('boom');

        $this->assertInstanceOf(ViewResponse::class, $response);
        $this->assertSame($path, $response->path());
    }

    public function test_make_carries_the_status_and_headers(): void
    {
        $this->write('page.php', 'page');

        $response = $this->renderer()->make('page', [], 404, ['X-Test' => 'yes']);

        $this->assertSame(404, $response->status());
    }

    /**
     * A view embedded in a larger document needs the markup, and `respond()`
     * needs it before `send()` so a failing view fails inside the action — where
     * the caller still owns the response — rather than mid-output.
     */
    public function test_respond_renders_eagerly_into_an_html_response(): void
    {
        $this->write('page.php', '<p>Saved.</p>');

        $response = $this->renderer()->respond('page', [], 201);

        $this->assertInstanceOf(HtmlResponse::class, $response);
        $this->assertSame('<p>Saved.</p>', $response->html());
        $this->assertSame(201, $response->status());
    }

    public function test_a_view_that_throws_is_reported_against_its_name(): void
    {
        $this->write('boom.php', '<?php throw new \RuntimeException("boom"); ?>');

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('boom');

        $this->renderer()->render('boom');
    }

    // ---------------------------------------------------------------------- roots

    /**
     * An absolute path in config is a path that is wrong on every other machine,
     * so a relative one is the normal case and has to resolve against the project
     * root rather than the working directory.
     */
    public function test_a_configured_relative_root_resolves_against_the_base_path(): void
    {
        $renderer = $this->renderer();

        $renderer->setRoot($this->viewDir);

        $this->assertSame($this->viewDir, $renderer->root());
    }

    public function test_a_second_root_can_be_pointed_at_for_one_process(): void
    {
        $other = $this->viewDir . '/other';
        mkdir($other, 0755, true);
        file_put_contents($other . '/mail.php', 'mail');

        $renderer = $this->renderer()->setRoot($other);

        $this->assertSame('mail', $renderer->render('mail'));
    }

    // -------------------------------------------------------------------- helpers

    private function renderer(): ViewRenderer
    {
        return (new ViewRenderer())->setRoot($this->viewDir);
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->viewDir . '/' . $relative;
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }
}
