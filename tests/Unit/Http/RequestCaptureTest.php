<?php
declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

/**
 * `Request::capture()` against a realistic `$_SERVER`.
 *
 * ## Why this runs in a separate process
 *
 * `capture()` reads six superglobals and `php://input`, and all seven are
 * process-global. A test that set `$_SERVER['REQUEST_METHOD'] = 'POST'` in the
 * shared suite would leave it set for every test that ran after it — and
 * `php://input` cannot be written to at all from inside the process reading it.
 * So each case runs its own PHP process, which is also the only way to give the
 * capture a body.
 *
 * ## What this is guarding against
 *
 * `capture()` was previously only ever exercised incidentally from a CLI run,
 * where `REQUEST_URI` is empty and `REQUEST_METHOD` is unset — so the empty
 * state was the *only* state it had been observed in. A bug that relied on a
 * populated value, or on a header key only a browser sends, would have been
 * invisible. These cases populate the superglobals the way a real request does.
 */
final class RequestCaptureTest extends TestCase
{
    /**
     * A GET a browser would send: a path, a query string, and the headers a
     * navigation carries.
     */
    public function test_it_captures_a_browser_get(): void
    {
        $result = $this->capture([
            'server' => [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/wp-json/wp-mini-laravel/v1/leads/42?filter=recent&page=2',
                'HTTP_HOST' => 'example.test',
                'HTTP_ACCEPT' => 'text/html,application/xhtml+xml',
                'HTTP_USER_AGENT' => 'Mozilla/5.0',
                'HTTPS' => 'on',
                'SERVER_PORT' => '443',
            ],
            'get' => ['filter' => 'recent', 'page' => '2'],
        ]);

        $this->assertSame('GET', $result['method']);
        $this->assertSame('/wp-json/wp-mini-laravel/v1/leads/42?filter=recent&page=2', $result['uri']);
        $this->assertSame('recent', $result['filter']);
        $this->assertSame('2', $result['page']);
        $this->assertSame('example.test', $result['host']);
        $this->assertTrue($result['secure']);
        $this->assertFalse($result['expectsJson']);
    }

    /**
     * A JSON POST is the headless case — but it is **not** testable here.
     *
     * `capture()` reads the payload from `php://input`, which is empty in every
     * CLI process: it is populated only by a web SAPI. Piping a body on stdin
     * does not fill it either. So a test that appeared to set one would be
     * asserting against a request PHP cannot produce, and would pass for the
     * wrong reason.
     *
     * The real coverage lives in `tests/manual/verify-http-lifecycle.php`, which
     * starts an HTTP server and POSTs an actual JSON body. This test records the
     * environment's limitation instead of pretending otherwise: it pins that a
     * JSON content type is still *detected* (that much is header-driven), while
     * the decoded body legitimately cannot arrive in this context.
     */
    public function test_a_json_content_type_is_detected_even_though_cli_cannot_supply_a_body(): void
    {
        $result = $this->capture([
            'server' => [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/wp-json/wp-mini-laravel/v1/leads',
                'HTTP_HOST' => 'example.test',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
        ]);

        $this->assertSame('POST', $result['method']);
        $this->assertTrue($result['isJson'], 'Detection is header-driven and works here.');
        $this->assertTrue($result['expectsJson']);
        $this->assertNull(
            $result['email'],
            'php://input is empty in CLI, so no body can be decoded — see the manual verification script.'
        );
    }

    /**
     * A classic form post, where the payload is in `$_POST` and the body stream
     * is empty — the inverse of the JSON case.
     */
    public function test_it_captures_a_form_post(): void
    {
        $result = $this->capture([
            'server' => [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/wp-admin/admin-post.php',
                'HTTP_HOST' => 'example.test',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ],
            'post' => ['name' => 'Ada', 'email' => 'ada@example.test'],
        ]);

        $this->assertSame('Ada', $result['name']);
        $this->assertSame('ada@example.test', $result['email']);
        $this->assertFalse($result['isJson']);
        $this->assertFalse($result['expectsJson']);
    }

    /**
     * A method override, which WordPress forms use to reach a verb a browser
     * cannot send — and the header canonicalization that decides whether the
     * content type is seen at all.
     */
    public function test_it_captures_a_method_override_and_a_forwarded_scheme(): void
    {
        $result = $this->capture([
            'server' => [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => '/wp-json/wp-mini-laravel/v1/leads/42',
                'HTTP_HOST' => 'example.test',
                // Behind a TLS-terminating proxy, so no HTTPS key exists.
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'CONTENT_TYPE' => 'application/json; charset=utf-8',
            ],
            'post' => ['_method' => 'DELETE'],
        ]);

        $this->assertSame('DELETE', $result['method']);
        $this->assertTrue($result['secure'], 'X-Forwarded-Proto must be honoured when HTTPS is absent.');
        $this->assertTrue($result['isJson'], 'A charset parameter must not defeat the content-type check.');
    }

    /**
     * The empty-server state the capture was previously only ever observed in.
     * It must degrade to a usable request rather than erroring.
     */
    public function test_it_captures_an_empty_server_without_erroring(): void
    {
        $result = $this->capture(['server' => []]);

        $this->assertSame('GET', $result['method']);
        $this->assertSame('/', $result['uri']);
        $this->assertFalse($result['secure']);
        $this->assertFalse($result['isJson']);
    }

    /**
     * @param array{server?: array<string, mixed>, get?: array<string, mixed>, post?: array<string, mixed>, input?: string} $env
     *
     * @return array<string, mixed>
     */
    private function capture(array $env): array
    {
        $script = sys_get_temp_dir() . '/wpmini-capture-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, $this->source($env));

        try {
            $output = shell_exec(sprintf(
                '%s %s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($script)
            ));
        } finally {
            @unlink($script);
        }

        $lines = array_values(array_filter(
            explode("\n", trim((string) $output)),
            static fn (string $line): bool => str_starts_with(trim($line), '{')
        ));

        $decoded = $lines === [] ? null : json_decode(trim(end($lines)), true);

        if (!is_array($decoded)) {
            $this->fail("The capture probe produced no readable result:\n" . $output);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array{server?: array<string, mixed>, get?: array<string, mixed>, post?: array<string, mixed>} $env
     */
    private function source(array $env): string
    {
        $root = dirname(__DIR__, 3);
        $server = var_export($env['server'] ?? [], true);
        $get = var_export($env['get'] ?? [], true);
        $post = var_export($env['post'] ?? [], true);

        return <<<PHP
        <?php
        declare(strict_types=1);

        require {$this->export($root . '/tests/stubs/wordpress.php')};
        require {$this->export($root . '/vendor/autoload.php')};

        // The superglobals are set before capture() runs, which is the whole
        // point of running this in its own process. php://input is *not* seeded:
        // it cannot be written from inside this process, which is why the body
        // cases are covered by the manual HTTP verification instead.
        \$_SERVER = {$server};
        \$_GET = {$get};
        \$_POST = {$post};
        \$_COOKIE = [];
        \$_FILES = [];

        \$request = \Framework\Http\Request::capture();

        echo json_encode([
            'method' => \$request->method(),
            'uri' => \$request->uri(),
            'filter' => \$request->input('filter'),
            'page' => \$request->input('page'),
            'host' => \$request->header('host'),
            'contentType' => \$request->header('content-type'),
            'secure' => \$request->isSecure(),
            'isJson' => \$request->isJson(),
            'expectsJson' => \$request->expectsJson(),
            'email' => \$request->input('email'),
            'name' => \$request->input('name'),
        ]), "\\n";
        PHP;
    }

    private function export(string $path): string
    {
        return var_export($path, true);
    }
}
