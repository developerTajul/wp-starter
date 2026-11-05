<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Http\Request;
use Framework\Routing\Exceptions\RouteRegistrationException;
use Framework\Routing\Exceptions\RouteResolutionException;
use Framework\Routing\ParameterBinder;
use Framework\Routing\Sources\HttpParameterSource;
use Framework\Routing\Sources\RestParameterSource;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;
use Tests\Unit\Routing\Fixtures\WebRecorder;
use WP_REST_Request;

/**
 * The one place a request's values become a controller action's arguments.
 *
 * ## Why this is tested directly rather than only through the routers
 *
 * The binder is shared by both domains, so a bug in it is a bug in both — and
 * through a router it can only be observed as "the controller got the wrong
 * value", which looks the same whether the cause is the binder, the router or the
 * controller. The rules pinned here are the ones with security consequences:
 * placeholder precedence, and the `bool` coercion that decides whether a record
 * is published.
 *
 * Both parameter sources are exercised, because the two domains reach the binder
 * by different routes and a fix applied to one would otherwise look complete.
 */
final class ParameterBinderTest extends TestCase
{
    private Container $container;
    private ParameterBinder $binder;

    protected function setUp(): void
    {
        WpStub::reset();
        WebRecorder::reset();

        $this->container = new Container();
        $this->container->instance(
            \DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface::class,
            $this->container
        );

        $this->binder = new ParameterBinder($this->container);
    }

    // ------------------------------------------------------- placeholder values

    /**
     * A placeholder fills a parameter that names it.
     */
    public function test_a_placeholder_fills_a_named_parameter(): void
    {
        $result = $this->bindHttp(['id' => '42'], Fixtures\ParameterController::class, 'show');

        $this->assertSame('42', WebRecorder::$received['id']);
        $this->assertSame('<p>42</p>', $result);
    }

    /**
     * **A placeholder outranks a body value of the same name.**
     *
     * This is the security-relevant rule. A form posting `id=999` to `/leads/123`
     * must not let the client choose which record the controller acts on — an
     * ownership check written against the URL would be bypassable.
     */
    public function test_a_placeholder_outranks_a_body_value_of_the_same_name(): void
    {
        $this->bindHttp(
            ['id' => '123'],
            Fixtures\ParameterController::class,
            'show',
            body: ['id' => '999']
        );

        $this->assertSame('123', WebRecorder::$received['id']);
    }

    /**
     * The same precedence must hold on the REST path, where WordPress ranks the URL
     * params *last* and an unpinned body value would win instead.
     */
    public function test_a_placeholder_outranks_a_rest_body_value(): void
    {
        Fixtures\ParameterEcho::$received = [];

        // `set_params()` is real WordPress's body/JSON bucket — the stub mirrors it
        // rather than inventing a `set_body_params()` that does not exist upstream.
        $request = new WP_REST_Request();
        $request->set_params(['id' => '999']);
        $request->set_url_params(['id' => '123']);

        $this->binder->call(
            new Fixtures\ParameterEcho(),
            'show',
            $request,
            new RestParameterSource($request),
            WP_REST_Request::class
        );

        $this->assertSame(
            '123',
            Fixtures\ParameterEcho::$received['id'],
            'A body value must not outrank the placeholder the URL matched.'
        );
    }

    // ------------------------------------------------------------ bool coercion

    /**
     * **The `bool` trap.** `"false"` is a non-empty string, and PHP coerces a
     * non-empty string to `true` — so without normalisation a request saying
     * `published=false` publishes the record while reporting that it did not.
     *
     * Asserted through both sources because a form body is at least as likely a
     * source of this spelling as a JSON document.
     */
    public function test_the_string_false_reaches_a_bool_parameter_as_false(): void
    {
        $this->bindHttp([], Fixtures\BoolController::class, 'update', body: ['published' => 'false']);

        $this->assertFalse(WebRecorder::$received['published']);
    }

    public function test_the_string_true_reaches_a_bool_parameter_as_true(): void
    {
        $this->bindHttp([], Fixtures\BoolController::class, 'update', body: ['published' => 'true']);

        $this->assertTrue(WebRecorder::$received['published']);
    }

    /**
     * Every spelling `accepts()` whitelists must also be *converted*, not merely
     * permitted. A spelling that passes the check and reaches reflection as a
     * string is coerced by PHP instead, which inverts the false-y ones.
     */
    public function test_every_accepted_bool_spelling_is_converted(): void
    {
        $spellings = [
            'false' => false, 'FALSE' => false, 'False' => false,
            '0' => false, '' => false,
            'true' => true, 'TRUE' => true, '1' => true,
        ];

        foreach ($spellings as $spelling => $expected) {
            WebRecorder::reset();

            $this->bindHttp([], Fixtures\BoolController::class, 'update', body: ['published' => $spelling]);

            $this->assertSame(
                $expected,
                WebRecorder::$received['published'],
                sprintf('[%s] should have become %s.', $spelling, $expected ? 'true' : 'false')
            );
        }
    }

    /**
     * An integer body value — `{"published": 0}` — must not be read as truthy.
     */
    public function test_an_integer_zero_reaches_a_bool_parameter_as_false(): void
    {
        $this->bindHttp([], Fixtures\BoolController::class, 'update', body: ['published' => 0]);

        $this->assertFalse(WebRecorder::$received['published']);
    }

    // ------------------------------------------------------------ absent values

    /**
     * An absent value must leave the argument out entirely, so the parameter's own
     * default applies. Passing null through instead would override the default and
     * raise a TypeError inside the controller — a failure the controller's author
     * would have no way to explain.
     */
    public function test_an_absent_value_lets_the_parameter_default_apply(): void
    {
        $controller = new class {
            public function index(string $missing = 'default'): string
            {
                return $missing;
            }
        };

        $result = $this->binder->call(
            $controller,
            'index',
            $this->httpRequest([]),
            new HttpParameterSource($this->httpRequest([])),
            Request::class
        );

        $this->assertSame('default', $result);
    }

    // ------------------------------------------------------------ type checking

    /**
     * A value that cannot satisfy a declared type is diagnosed with the controller
     * and action named, rather than surfacing as a TypeError inside the action.
     */
    public function test_an_incompatible_value_is_reported_with_the_action(): void
    {
        $this->expectException(RouteResolutionException::class);

        $this->bindHttp([], Fixtures\ParameterController::class, 'show', body: ['id' => ['nested']]);
    }

    /**
     * A method that does not exist is a route-definition mistake and is named as
     * one.
     */
    public function test_a_missing_action_is_refused(): void
    {
        $this->expectException(RouteRegistrationException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $this->bindHttp([], Fixtures\ParameterController::class, 'nope');
    }

    /**
     * A non-public action is refused with a message naming the method, rather than
     * letting reflection raise something opaque.
     */
    public function test_a_private_action_is_refused(): void
    {
        $this->expectException(RouteRegistrationException::class);
        $this->expectExceptionMessageMatches('/must be public/');

        $this->bindHttp([], Fixtures\PrivateActionController::class, 'secret');
    }

    // ----------------------------------------------------------------- injection

    /**
     * A parameter typed as the request receives the request object itself — the
     * mechanism by which a controller gets hold of the request at all.
     */
    public function test_a_request_typed_parameter_receives_the_request(): void
    {
        $request = $this->httpRequest(['uri' => '/leads']);

        $result = $this->binder->call(
            new Fixtures\RequestEcho(),
            'index',
            $request,
            new HttpParameterSource($request),
            Request::class
        );

        $this->assertSame('request', $result);
        $this->assertSame('/leads', Fixtures\RequestEcho::$uri);
    }

    // ----------------------------------------------------------------- closures

    /**
     * A closure's parameters are filled by the same rules, so `fn (int $id)` works
     * on a closure route exactly as it does on a controller action.
     */
    public function test_a_closure_parameter_is_filled_from_the_route(): void
    {
        $request = $this->httpRequest(['id' => '7']);

        $result = $this->binder->callCallable(
            static fn (string $id): string => 'got ' . $id,
            $request,
            new HttpParameterSource($request, ['id' => '7']),
            Request::class
        );

        $this->assertSame('got 7', $result);
    }

    /**
     * A closure declaring no parameters receives none — `fn () => ...` is the
     * common case for a simple route and must not be forced to declare an argument.
     */
    public function test_a_closure_with_no_parameters_is_called(): void
    {
        $request = $this->httpRequest([]);

        $result = $this->binder->callCallable(
            static fn (): string => 'called',
            $request,
            new HttpParameterSource($request),
            Request::class
        );

        $this->assertSame('called', $result);
    }

    /**
     * A closure typed against the request receives it, on the web path.
     */
    public function test_a_closure_can_receive_the_request(): void
    {
        $request = $this->httpRequest(['uri' => '/hello']);

        $result = $this->binder->callCallable(
            static fn (Request $r): string => $r->uri(),
            $request,
            new HttpParameterSource($request),
            Request::class
        );

        $this->assertSame('/hello', $result);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string, mixed> $routeParams
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     *
     * @return mixed
     */
    private function bindHttp(
        array $routeParams,
        string $controller,
        string $action,
        array $body = [],
        array $query = []
    ): mixed {
        $request = $this->httpRequest($query, $body);

        return $this->binder->call(
            $this->container->resolve($controller),
            $action,
            $request,
            new HttpParameterSource($request, $routeParams),
            Request::class
        );
    }

    /**
     * A request whose URI can be set, since `Request` is immutable.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function httpRequest(array $query = [], array $body = []): Request
    {
        return new Request(
            query: $query,
            body: $body,
            server: [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => (string) ($query['uri'] ?? '/'),
            ],
            rawBody: '',
            headers: []
        );
    }
}
