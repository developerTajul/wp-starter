<?php
declare(strict_types=1);

namespace Tests\Unit\Http;

use Framework\Http\Exceptions\HttpException;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\RedirectResponse;
use Framework\Http\Responses\ViewResponse;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

final class ResponseTest extends TestCase
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
        foreach (glob($this->viewDir . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->viewDir)) {
            rmdir($this->viewDir);
        }
    }

    // ------------------------------------------------------------- JsonResponse

    public function test_a_json_response_sends_its_data_and_status(): void
    {
        (new JsonResponse(['ok' => true], ['status_code' => 201]))->send();

        $this->assertSame(
            [['data' => ['ok' => true], 'status' => 201]],
            WpStub::$jsonResponses
        );
    }

    public function test_a_json_response_defaults_to_200(): void
    {
        $response = new JsonResponse(['ok' => true]);

        $this->assertSame(200, $response->status());

        $response->send();

        $this->assertSame(200, WpStub::$jsonResponses[0]['status']);
    }

    /**
     * `withStatus()` and `with()` return copies, because a response is often
     * built from a shared value and mutating it would change every other holder.
     */
    public function test_with_status_returns_a_copy_rather_than_mutating(): void
    {
        $original = new JsonResponse(['ok' => true]);

        $changed = $original->withStatus(422);

        $this->assertNotSame($original, $changed);
        $this->assertSame(200, $original->status());
        $this->assertSame(422, $changed->status());
    }

    public function test_sending_a_json_response_twice_is_refused(): void
    {
        $response = new JsonResponse(['ok' => true]);
        $response->send();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('already been sent');

        $response->send();
    }

    // --------------------------------------------------------- RedirectResponse

    /**
     * `send()` calls `exit`, which would end the PHPUnit process — so every test
     * that sends runs in its own. The recorded calls are read back through the
     * stub file the child process writes, because PHPUnit's own state does not
     * survive the exit.
     *
     * That is heavier than asserting on a return value, and it is the only way
     * to test a method whose contract is to terminate the request. The behaviour
     * it pins down — that a redirect really does exit — is exactly the part that
     * would otherwise be untested.
     */
    public function test_a_redirect_sends_the_url_and_status(): void
    {
        $recorded = $this->sendAndReport(
            static fn (): RedirectResponse => new RedirectResponse('https://example.test/next')
        );

        $this->assertSame('https://example.test/next', $recorded['url']);
        $this->assertSame(302, $recorded['status']);
    }

    public function test_a_redirect_can_use_a_303(): void
    {
        $recorded = $this->sendAndReport(
            static fn (): RedirectResponse => new RedirectResponse('https://example.test/next', 303)
        );

        $this->assertSame(303, $recorded['status']);
    }

    /**
     * A non-3xx status is refused at construction, not at send().
     *
     * WordPress responds to one by calling wp_die() — `wp_redirect()` does
     * `if ( $status < 300 || 399 < $status ) wp_die(...)`, and does **not**
     * quietly fall back to 302. So the request is over before send() returns,
     * and throwing here is what turns an error page into a stack trace that
     * names the line that supplied the bad status.
     *
     * @dataProvider badRedirectStatuses
     */
    public function test_a_non_redirect_status_is_refused(int $status): void
    {
        $this->expectException(HttpException::class);

        new RedirectResponse('https://example.test/next', $status);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function badRedirectStatuses(): array
    {
        return [
            '200 OK' => [200],
            '201 Created' => [201],
            '403 Forbidden' => [403],
            '304 Not Modified' => [304],
            '500 Server Error' => [500],
            'a typo: 30' => [30],
            'a typo: 3000' => [3000],
            'zero is not "default"' => [0],
            'negative' => [-1],
        ];
    }

    public function test_flash_values_are_written_to_a_transient_before_the_redirect(): void
    {
        $recorded = $this->sendAndReport(static function (): RedirectResponse {
            return (new RedirectResponse('https://example.test/next'))
                ->with('success', 'Saved')
                ->with('count', 3);
        });

        $this->assertSame(
            [['key' => $recorded['transientKeys'][0] ?? '', 'value' => ['success' => 'Saved', 'count' => 3]]],
            [[
                'key' => $recorded['transientKeys'][0] ?? '',
                'value' => $recorded['transientValues'][0] ?? null,
            ]]
        );
        $this->assertSame(RedirectResponse::FLASH_TTL, $recorded['transientTtls'][0] ?? null);
    }

    public function test_a_redirect_without_flash_writes_nothing(): void
    {
        $recorded = $this->sendAndReport(
            static fn (): RedirectResponse => new RedirectResponse('https://example.test/next')
        );

        $this->assertSame([], $recorded['transientKeys']);
        $this->assertSame([], $recorded['cookieNames']);
    }

    /**
     * The key is per-client, because a transient is site-global: a shared key
     * would hand one visitor's flash message to the next visitor.
     */
    public function test_flash_is_written_under_a_user_scoped_key_when_logged_in(): void
    {
        $recorded = $this->sendAndReport(
            static fn (): RedirectResponse => (new RedirectResponse('https://example.test/next'))->with('success', 'S'),
            ['userId' => 7]
        );

        $this->assertStringContainsString('flash_7', $recorded['transientKeys'][0] ?? '');
        $this->assertSame([], $recorded['cookieNames'], 'A logged-in user needs no cookie.');
    }

    public function test_an_anonymous_redirect_issues_a_flash_cookie(): void
    {
        $recorded = $this->sendAndReport(
            static fn (): RedirectResponse => (new RedirectResponse('https://example.test/next'))->with('success', 'S')
        );

        $this->assertSame([RedirectResponse::FLASH_COOKIE], $recorded['cookieNames']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $recorded['cookieValues'][0] ?? '');
        $this->assertStringContainsString($recorded['cookieValues'][0] ?? '', $recorded['transientKeys'][0] ?? '');
    }

    /**
     * The flash cookie's `Secure` flag follows the *request*, not
     * `$_SERVER['HTTPS']` alone — which is unset behind a TLS-terminating proxy.
     * Reading the superglobal directly would issue the cookie without `Secure` on
     * a site that is genuinely HTTPS to the client.
     */
    public function test_the_flash_cookie_is_secure_when_the_request_is(): void
    {
        $recorded = $this->sendAndReport(
            static fn (): RedirectResponse => (new RedirectResponse('https://example.test/next'))->with('success', 'S'),
            ['headers' => ['x-forwarded-proto' => 'https']]
        );

        $this->assertSame([true], $recorded['cookieSecure']);
    }

    public function test_the_flash_cookie_is_not_secure_over_plain_http(): void
    {
        $recorded = $this->sendAndReport(
            static fn (): RedirectResponse => (new RedirectResponse('http://example.test/next'))->with('success', 'S'),
            ['headers' => []]
        );

        $this->assertSame([false], $recorded['cookieSecure']);
    }

    public function test_read_flash_returns_and_consumes_the_stored_values(): void
    {        WpStub::$currentUserId = 7;
        WpStub::$transients['wp_mini_laravel_flash_7'] = ['success' => 'Saved'];

        $this->assertSame(['success' => 'Saved'], RedirectResponse::readFlash());

        // Consumed: a second read must find nothing, or the message would
        // reappear on every refresh.
        $this->assertSame([], RedirectResponse::readFlash());
        $this->assertArrayNotHasKey('wp_mini_laravel_flash_7', WpStub::$transients);
    }

    public function test_read_flash_is_empty_for_an_anonymous_client_with_no_cookie(): void
    {
        WpStub::$transients['wp_mini_laravel_flash_' . str_repeat('a', 32)] = ['success' => 'x'];

        $this->assertSame([], RedirectResponse::readFlash());
    }

    /**
     * A cookie value is client-supplied. It is used to *look up* a key this site
     * wrote, never as an identifier itself, so a crafted value must not be able
     * to address an arbitrary transient.
     */
    public function test_a_malformed_flash_cookie_is_ignored(): void
    {
        $_COOKIE[RedirectResponse::FLASH_COOKIE] = '../../evil';

        try {
            $this->assertSame([], RedirectResponse::readFlash());
        } finally {
            unset($_COOKIE[RedirectResponse::FLASH_COOKIE]);
        }
    }

    // ------------------------------------------------------------- ViewResponse

    public function test_a_view_renders_with_its_data(): void
    {
        $path = $this->view('Hello <?= $name ?>');

        $this->assertSame(
            'Hello Ada',
            (new ViewResponse($path, ['name' => 'Ada']))->render()
        );
    }

    /**
     * Buffering is what makes a view capturable: `render()` must return the
     * output without writing it, which is what lets a view be embedded in a
     * larger document or asserted on.
     */
    public function test_rendering_does_not_write_to_the_output_buffer(): void
    {
        $this->expectOutputString('');

        (new ViewResponse($this->view('silent'), []))->render();
    }

    public function test_sending_a_view_writes_its_output(): void
    {
        $this->expectOutputString('rendered');

        (new ViewResponse($this->view('rendered'), []))->send();
    }

    public function test_a_status_other_than_200_is_set_on_the_response(): void
    {
        // send() echoes the view; the expectation is what keeps the suite's
        // "no unexpected output" rule satisfied rather than the body leaking into
        // the runner's report.
        $this->expectOutputString('nope');

        (new ViewResponse($this->view('nope'), [], 404))->send();

        $this->assertSame([404], WpStub::$statusHeaders);
    }

    public function test_a_200_view_does_not_set_a_status_header(): void
    {
        // 200 is the status a response already has, so setting it would be a
        // no-op header — and calling status_header() for it makes the framework
        // look like it changed something it did not.
        $this->expectOutputString('ok');

        (new ViewResponse($this->view('ok'), []))->send();

        $this->assertSame([], WpStub::$statusHeaders);
    }

    public function test_a_missing_view_is_reported_rather_than_included(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('does not exist');

        (new ViewResponse($this->viewDir . '/nope.php'))->render();
    }

    /**
     * A view that throws must not leave its partial output in the buffer, or the
     * error page would be prefixed with half a page of the failed view.
     */
    public function test_a_view_that_throws_leaves_no_partial_output_behind(): void
    {
        $path = $this->view('partial<?php throw new \RuntimeException("boom"); ?>');

        try {
            (new ViewResponse($path))->render();
            $this->fail('The view should have thrown.');
        } catch (HttpException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
            $this->assertSame('', (string) ob_get_clean(), 'The buffer must be clean after a failed render.');
            ob_start();
        }
    }

    /**
     * A view cannot silently overwrite the renderer's own variables — a view that
     * thinks it set `$path` and did not is far harder to debug than one that
     * refuses to render.
     */
    public function test_a_view_may_not_use_a_reserved_variable_name(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('reserved');

        (new ViewResponse($this->view('x'), ['path' => 'hijacked']))->render();
    }

    public function test_a_view_can_be_given_nested_data(): void
    {
        $path = $this->view('<?= $leads[0]["name"] ?>');

        $this->assertSame('Ada', (new ViewResponse($path, ['leads' => [['name' => 'Ada']]]))->render());
    }

    public function test_a_view_rendered_twice_is_not_refused(): void
    {
        // render() is side-effect free; only send() is terminal. A view embedded
        // in two places must keep working.
        $response = new ViewResponse($this->view('twice'));

        $this->assertSame('twice', $response->render());
        $this->assertSame('twice', $response->render());
    }

    // ------------------------------------------------------------------- helpers

    private function view(string $contents): string
    {
        $path = $this->viewDir . '/' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Build a RedirectResponse, send it in a child process, and report what the
     * WordPress stubs recorded.
     *
     * A child process rather than PHPUnit's `#[RunInSeparateProcess]` because
     * what has to survive is not PHPUnit's state but the *stub state*, and the
     * exit happens after the recording either way. The child prints JSON on its
     * last line; anything before it (a PHP warning, a stray notice) is ignored
     * so a noisy include path cannot be mistaken for a failed assertion.
     *
     * @param callable(): RedirectResponse    $make
     * @param array{userId?: int, nonce?: string} $stub
     * @return array{
     *     url: string, status: int,
     *     transientKeys: list<string>, transientValues: list<mixed>, transientTtls: list<int>,
     *     cookieNames: list<string>, cookieValues: list<string>
     * }
     */
    private function sendAndReport(callable $make, array $stub = []): array
    {
        // The response is built in *this* process so the test reads naturally,
        // then reconstructed in the child from its constructor arguments — a
        // closure cannot cross a process boundary.
        $response = $make();

        $script = $this->viewDir . '/send-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, $this->childSource($response, $stub));

        $output = shell_exec(sprintf(
            '%s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($script)
        ));

        $lines = array_values(array_filter(
            explode("\n", trim((string) $output)),
            static fn (string $line): bool => str_starts_with(trim($line), '{')
        ));

        $decoded = $lines === [] ? null : json_decode(trim(end($lines)), true);

        if (!is_array($decoded)) {
            $this->fail("The redirect probe produced no readable result:\n" . $output);
        }

        /** @var array{url: string, status: int, transientKeys: list<string>, transientValues: list<mixed>, transientTtls: list<int>, cookieNames: list<string>, cookieValues: list<string>, cookieSecure: list<mixed>} $decoded */
        return $decoded;
    }

    /**
     * The child process's source: boot the stubs, reproduce the response, send
     * it, and print what was recorded.
     */
    private function childSource(RedirectResponse $response, array $stub): string
    {
        $root = dirname(__DIR__, 3);
        $flash = var_export($response->flash(), true);
        $url = var_export($response->url(), true);
        $status = $response->status();
        $userId = (int) ($stub['userId'] ?? 0);
        $cookieToken = (string) ($stub['cookieToken'] ?? '');
        $headers = var_export($stub['headers'] ?? [], true);

        // `exit` inside send() ends this script; the echo below it therefore
        // never runs, which is the behaviour under test. The report is printed by
        // a shutdown function registered first.
        return <<<PHP
        <?php
        declare(strict_types=1);

        require {$this->export($root . '/tests/stubs/wordpress.php')};
        require {$this->export($root . '/vendor/autoload.php')};
        require {$this->export($root . '/tests/stubs/wp-classes.php')};
        // The builtin double, which is what records the flash cookie. Without it
        // the child calls PHP's real setcookie() and the cookie is invisible.
        require {$this->export($root . '/tests/stubs/builtins.php')};

        \\Tests\\Support\\WpStub::\$currentUserId = {$userId};

        register_shutdown_function(static function (): void {
            echo json_encode([
                'url' => \\Tests\\Support\\WpStub::\$redirects[0]['url'] ?? '',
                'status' => \\Tests\\Support\\WpStub::\$redirects[0]['status'] ?? 0,
                'transientKeys' => array_column(\\Tests\\Support\\WpStub::\$transientWrites, 'key'),
                'transientValues' => array_column(\\Tests\\Support\\WpStub::\$transientWrites, 'value'),
                'transientTtls' => array_column(\\Tests\\Support\\WpStub::\$transientWrites, 'expiration'),
                'cookieNames' => array_column(\\Tests\\Support\\WpStub::\$cookies, 'name'),
                'cookieValues' => array_column(\\Tests\\Support\\WpStub::\$cookies, 'value'),
                'cookieSecure' => array_map(
                    static fn (array \$c): mixed => \$c['options']['secure'] ?? null,
                    \\Tests\\Support\\WpStub::\$cookies
                ),
            ]), "\\n";
        });

        \$request = new \\Framework\\Http\\Request(headers: {$headers});

        \$redirect = new \\Framework\\Http\\Responses\\RedirectResponse({$url}, {$status}, \$request);

        foreach ({$flash} as \$key => \$value) {
            \$redirect->with((string) \$key, \$value);
        }

        \$redirect->send();
        PHP;
    }

    private function export(string $path): string
    {
        return var_export($path, true);
    }
}
