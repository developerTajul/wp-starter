<?php
declare(strict_types=1);

namespace Tests\Unit\Http;

use Core\Shared\Domain\Contracts\RateLimiterContract;
use Core\Shared\RateLimitResult;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Exceptions\TooManyRequestsHttpException;
use Framework\Http\Middleware\ThrottleRequests;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use PHPUnit\Framework\TestCase;

final class ThrottleRequestsTest extends TestCase
{
    /**
     * A limiter that decides from a scripted queue rather than from state.
     *
     * Written rather than pointed at the real database limiter because what is
     * under test here is the *middleware* — how it parses parameters, composes its
     * key, and translates a denial into an exception. Driving it through a real
     * table would test the adapter instead, and would make "the fourth call is
     * denied" a fact about the limiter rather than about this class.
     *
     * The recorded keys are the point: they are the only way to assert that two
     * routes do not share an allowance.
     */
    private function scriptedLimiter(array $results, ?array &$keys = null): RateLimiterContract
    {
        return new class ($results, $keys) implements RateLimiterContract {
            /** @param list<RateLimitResult> $results */
            public function __construct(private array $results, private ?array &$keys)
            {
            }

            public function hit(string $key, int $maxAttempts, int $decaySeconds): RateLimitResult
            {
                if ($this->keys !== null) {
                    $this->keys[] = [
                        'key' => $key,
                        'max' => $maxAttempts,
                        'decay' => $decaySeconds,
                    ];
                }

                return array_shift($this->results) ?? RateLimitResult::allowed(1, $maxAttempts, $decaySeconds);
            }

            public function clear(string $key): void
            {
            }
        };
    }

    private function request(string $uri = '/leads', string $ip = '1.2.3.4'): Request
    {
        return new Request(server: ['REQUEST_URI' => $uri, 'REMOTE_ADDR' => $ip]);
    }

    /** @return callable(): ResponseInterface */
    private function destination(): callable
    {
        return static fn (): ResponseInterface => new JsonResponse(['ok' => true]);
    }

    /**
     * Run the middleware by hand rather than through the pipeline.
     *
     * The pipeline exists to resolve middleware from the container and would add a
     * container to every case here for no assertion. The behaviour under test is
     * `handle()`'s, and it is reachable directly.
     */
    private function dispatch(ThrottleRequests $middleware, Request $request, string ...$arguments): ResponseInterface
    {
        return $middleware->handle($request, $this->destination(), ...$arguments);
    }

    // -------------------------------------------------------------- parameters

    public function test_a_request_within_the_limit_passes_through(): void
    {
        $middleware = new ThrottleRequests($this->scriptedLimiter([
            RateLimitResult::allowed(1, 60, 60),
        ]));

        $response = $this->dispatch($middleware, $this->request(), '60', '1');

        $this->assertSame(200, $response->status());
    }

    public function test_the_limit_and_window_are_read_from_the_arguments(): void
    {
        $keys = [];
        $middleware = new ThrottleRequests($this->scriptedLimiter([], $keys));

        $this->dispatch($middleware, $this->request(), '5', '10');

        // The window arrives in minutes from the route and must reach the limiter
        // in seconds — a unit confusion here would silently multiply or divide
        // every limit in the application by sixty.
        $this->assertSame(5, $keys[0]['max']);
        $this->assertSame(600, $keys[0]['decay']);
    }

    public function test_a_missing_window_defaults_to_one_minute(): void
    {
        $keys = [];
        $middleware = new ThrottleRequests($this->scriptedLimiter([], $keys));

        $this->dispatch($middleware, $this->request(), '60');

        $this->assertSame(60, $keys[0]['decay']);
    }

    public function test_no_arguments_is_refused_rather_than_treated_as_no_limit(): void
    {
        // `->middleware('throttle')` is a typo, and treating it as "unlimited"
        // would make the typo invisible on exactly the route someone was trying to
        // protect.
        $middleware = new ThrottleRequests($this->scriptedLimiter([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs a limit/');

        $this->dispatch($middleware, $this->request());
    }

    public function test_a_non_numeric_limit_is_refused(): void
    {
        $middleware = new ThrottleRequests($this->scriptedLimiter([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a usable number/');

        $this->dispatch($middleware, $this->request(), 'lots', '1');
    }

    public function test_a_zero_limit_is_refused(): void
    {
        $middleware = new ThrottleRequests($this->scriptedLimiter([]));

        $this->expectException(\InvalidArgumentException::class);

        $this->dispatch($middleware, $this->request(), '0', '1');
    }

    public function test_a_zero_minute_window_still_produces_a_positive_window(): void
    {
        // `max(1, $decayMinutes)` — a zero-second window would reset instantly,
        // which is a limiter that never limits. Clamped rather than refused because
        // the limiter itself refuses a sub-second window, and a floor of one minute
        // is the least surprising reading of `throttle:60,0`.
        $keys = [];
        $middleware = new ThrottleRequests($this->scriptedLimiter([], $keys));

        $this->dispatch($middleware, $this->request(), '60', '0');

        $this->assertSame(60, $keys[0]['decay']);
    }

    // ----------------------------------------------------------------- denial

    public function test_a_denial_raises_a_429_with_the_retry_window(): void
    {
        $middleware = new ThrottleRequests($this->scriptedLimiter([
            RateLimitResult::denied(61, 60, 42),
        ]));

        try {
            $this->dispatch($middleware, $this->request(), '60', '1');
            $this->fail('The middleware should have refused the request.');
        } catch (TooManyRequestsHttpException $e) {
            $this->assertSame(429, $e->statusCode());

            // The header is the whole point: a 429 that cannot say when to retry
            // invites the immediate retry the limit exists to prevent.
            $this->assertSame('42', $e->headers()['Retry-After']);
            $this->assertSame(42, $e->retryAfter());
        }
    }

    // ------------------------------------------------------------------- keys

    public function test_two_routes_do_not_share_one_allowance(): void
    {
        $keys = [];
        $middleware = new ThrottleRequests($this->scriptedLimiter([], $keys));

        $this->dispatch($middleware, $this->request('/leads/create'), '60', '1');
        $this->dispatch($middleware, $this->request('/leads/store'), '60', '1');

        $this->assertNotSame($keys[0]['key'], $keys[1]['key']);
    }

    public function test_the_query_string_does_not_create_a_second_bucket(): void
    {
        // A paginating client must not get a fresh allowance per page.
        $keys = [];
        $middleware = new ThrottleRequests($this->scriptedLimiter([], $keys));

        $this->dispatch($middleware, $this->request('/leads?page=2'), '60', '1');
        $this->dispatch($middleware, $this->request('/leads?page=3'), '60', '1');

        $this->assertSame($keys[0]['key'], $keys[1]['key']);
    }

    public function test_two_clients_on_one_route_do_not_share_one_allowance(): void
    {
        $keys = [];
        $middleware = new ThrottleRequests($this->scriptedLimiter([], $keys));

        $this->dispatch($middleware, $this->request('/leads', '1.1.1.1'), '60', '1');
        $this->dispatch($middleware, $this->request('/leads', '2.2.2.2'), '60', '1');

        $this->assertNotSame($keys[0]['key'], $keys[1]['key']);
    }

    public function test_a_forwarded_for_header_is_not_trusted(): void
    {
        // Honouring it would let any caller pick a fresh counter per request,
        // which does not weaken the limit — it removes it.
        $keys = [];
        $middleware = new ThrottleRequests($this->scriptedLimiter([], $keys));

        $withHeader = new Request(
            server: ['REQUEST_URI' => '/leads', 'REMOTE_ADDR' => '1.2.3.4'],
            headers: ['x-forwarded-for' => '9.9.9.9']
        );

        $this->dispatch($middleware, $withHeader, '60', '1');
        $this->dispatch($middleware, $this->request('/leads', '1.2.3.4'), '60', '1');

        $this->assertSame($keys[0]['key'], $keys[1]['key']);
    }

    public function test_a_missing_remote_address_falls_back_to_one_shared_bucket(): void
    {
        // Failing closed: an unknown client shares a bucket rather than escaping
        // the limit entirely.
        $keys = [];
        $middleware = new ThrottleRequests($this->scriptedLimiter([], $keys));

        $bare = new Request(server: ['REQUEST_URI' => '/leads']);

        $this->dispatch($middleware, $bare, '60', '1');

        $this->assertStringContainsString('unknown', $keys[0]['key']);
    }
}
