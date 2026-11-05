<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Http\Request;
use Framework\Routing\WebRequestFactory;

/**
 * Builds a request the test controls, instead of capturing the superglobals.
 *
 * In a PHPUnit process `Request::capture()` reads an empty `$_GET`/`$_POST` and
 * cannot read `php://input` at all, so a test that wants to dispatch an
 * admin-post handler with a specific body has no way to supply one through
 * production code. Binding this over {@see \Framework\Routing\CaptureWebRequestFactory}
 * is the same move `HttpRequestBindingTest` makes for REST.
 *
 * The built request mirrors the production factory's layering — URL parameters
 * folded over both the query and the body — so a test cannot pass against an
 * overlay that production does not perform.
 */
final class FakeWebRequestFactory implements WebRequestFactory
{
    /** @var array<int, array<string, string>> */
    public static array $calls = [];

    /** @var array<string, mixed> */
    public static array $query = [];

    /** @var array<string, mixed> */
    public static array $body = [];

    /** @var array<string, mixed> */
    public static array $server = [];

    /** @var array<string, mixed> */
    public static array $files = [];

    /** @var array<string, mixed> */
    public static array $cookies = [];

    public static string $method = 'GET';

    public static string $uri = '/';

    public function make(array $urlParams = []): Request
    {
        // Recorded so a test can assert *what* the dispatcher asked for — a route
        // whose parameters never reached the factory is the failure this catches,
        // and it is invisible from the controller's side because the action simply
        // receives its parameter's default.
        self::$calls[] = $urlParams;

        return new Request(
            query: array_replace(self::$query, $urlParams),
            body: array_replace(self::$body, $urlParams),
            server: array_replace([
                'REQUEST_METHOD' => self::$method,
                'REQUEST_URI' => self::$uri,
            ], self::$server),
            files: self::$files,
            cookies: self::$cookies,
            rawBody: '',
            headers: []
        );
    }

    public static function reset(): void
    {
        self::$calls = [];
        self::$query = [];
        self::$body = [];
        self::$server = [];
        self::$files = [];
        self::$cookies = [];
        self::$method = 'GET';
        self::$uri = '/';
    }
}
