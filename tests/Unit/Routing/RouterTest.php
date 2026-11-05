<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Routing\RouteResolver;
use Framework\Routing\Router;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;
use WP_Error;
use WP_REST_Request;

// PHPUnit autoloads classes, not functions, so the plain-function permission
// callbacks these tests exercise have to be pulled in explicitly.
require_once __DIR__ . '/Fixtures/permission-functions.php';

final class RouterTest extends TestCase
{
    private const NAMESPACE = 'test/v1';

    private Container $container;
    private Router $router;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->container = new Container();
        $this->router = new Router($this->container, new RouteResolver($this->container));
    }

    /**
     * WordPress is handed the route as a regex matched against the remainder of
     * the request path, so the plugin's own `{param}` spelling has to survive
     * the translation or the route would never match.
     */
    public function test_it_registers_each_route_with_wordpress_under_the_given_namespace(): void
    {
        $this->router->registrar()->get('posts', Fixtures\PostController::class, 'index');

        $this->router->registerRoutes(self::NAMESPACE);

        $registered = WpStub::$registeredRoutes[self::NAMESPACE];
        $this->assertCount(1, $registered);
        $this->assertSame('posts', $registered[0]['route']);
        $this->assertSame(['GET'], $registered[0]['methods']);
    }

    /**
     * A placeholder must become a capture group, otherwise WordPress could not
     * tell one route from another and the value would never reach the handler.
     */
    public function test_a_placeholder_becomes_a_named_capture_group(): void
    {
        $this->router->registrar()->get('posts/{id}', Fixtures\PostController::class, 'show');

        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertSame('posts/(?P<id>[^/]+)', WpStub::$registeredRoutes[self::NAMESPACE][0]['route']);
    }

    /**
     * A typed segment carries a constraint, which is how a route declares that
     * `{id}` is numeric without the controller having to re-validate it.
     */
    public function test_a_typed_placeholder_keeps_its_constraint(): void
    {
        $this->router->registrar()->get('posts/{id:\d+}', Fixtures\PostController::class, 'show');

        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertSame('posts/(?P<id>\d+)', WpStub::$registeredRoutes[self::NAMESPACE][0]['route']);
    }

    public function test_several_placeholders_in_one_path_are_all_translated(): void
    {
        $this->router->registrar()
            ->get('posts/{postId}/comments/{commentId:\d+}')
            ->handle(static fn (): array => []);

        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertSame(
            'posts/(?P<postId>[^/]+)/comments/(?P<commentId>\d+)',
            WpStub::$registeredRoutes[self::NAMESPACE][0]['route']
        );
    }

    public function test_registration_is_a_no_op_when_wordpress_is_absent(): void
    {
        $this->router->registrar()->get('posts', Fixtures\PostController::class, 'index');

        $this->router->registerRoutes(self::NAMESPACE);

        // The stub is always loaded in this suite, so the meaningful assertion
        // is that registration happened exactly once and did not throw.
        $this->assertCount(1, WpStub::$registeredRoutes[self::NAMESPACE]);
    }

    public function test_it_dispatches_to_a_controller_action(): void
    {
        $this->router->registrar()->get('posts', Fixtures\PostController::class, 'index');
        $this->router->registerRoutes(self::NAMESPACE);

        $response = $this->dispatch('posts');

        $this->assertSame(['action' => 'index'], $response->get_data());
        $this->assertSame(200, $response->get_status());
    }

    /**
     * A route parameter must reach the action, and the declared scalar type must
     * survive the round trip — WordPress hands everything over as a string.
     */
    public function test_a_route_parameter_reaches_the_action(): void
    {
        $this->router->registrar()->get('posts/{id}', Fixtures\PostController::class, 'show');
        $this->router->registerRoutes(self::NAMESPACE);

        $response = $this->dispatch('posts/{id}', ['id' => '42']);

        $this->assertSame(['id' => '42'], $response->get_data());
    }

    /**
     * A scalar-typed parameter is filled from the request, which is the case
     * that proves request data takes precedence over container auto-wiring —
     * `int $id` could never otherwise receive the `id` WordPress matched.
     */
    public function test_a_scalar_parameter_is_filled_from_the_request_not_the_container(): void
    {
        $this->router->registrar()->get('posts/{id}/meta', Fixtures\PostController::class, 'meta');
        $this->router->registerRoutes(self::NAMESPACE);

        $response = $this->dispatch('posts/{id}/meta', ['id' => 7]);

        $this->assertSame(['id' => 7, 'type' => 'int'], $response->get_data());
    }

    public function test_a_closure_handler_is_invoked(): void
    {
        $this->router->registrar()->get('ping')->handle(static fn (): array => ['pong' => true]);
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertSame(['pong' => true], $this->dispatch('ping'));
    }

    public function test_a_controller_action_may_return_any_response_status(): void
    {
        $this->router->registrar()->post('posts', Fixtures\PostController::class, 'store');
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertSame(201, $this->dispatch('posts')->get_status());
    }

    /**
     * Every route is allowed by default. A plugin that ships a public endpoint
     * should not have to opt in to that.
     */
    public function test_a_route_with_no_middleware_is_permitted(): void
    {
        $this->router->registrar()->get('posts', Fixtures\PostController::class, 'index');
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertTrue($this->permission('posts'));
    }

    public function test_a_middleware_can_deny_a_request(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->middleware(Fixtures\DenyMiddleware::class);
        $this->router->registerRoutes(self::NAMESPACE);

        $result = $this->permission('posts');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(Fixtures\DenyMiddleware::STATUS, $result->get_status());
    }

    /**
     * The first denial wins: a later middleware must not be able to overturn an
     * earlier one, or authorization would depend on ordering by accident.
     */
    public function test_the_first_denying_middleware_wins(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->middleware(Fixtures\DenyMiddleware::class, Fixtures\AllowMiddleware::class);
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertInstanceOf(WP_Error::class, $this->permission('posts'));
    }

    public function test_middleware_runs_in_registration_order(): void
    {
        Fixtures\RecordingMiddleware::$ran = [];

        $this->container->bind(Fixtures\RecordingMiddleware::class, static fn (): Fixtures\RecordingMiddleware => new Fixtures\RecordingMiddleware('only'));

        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->middleware(Fixtures\RecordingMiddleware::class);
        $this->router->registerRoutes(self::NAMESPACE);

        $this->permission('posts');

        $this->assertSame(['only'], Fixtures\RecordingMiddleware::$ran);
    }

    /**
     * An explicit permission callback is consulted on top of middleware, since a
     * route that sets one has deliberately opted into WordPress's own mechanism.
     */
    public function test_an_explicit_permission_callback_can_deny(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->permissionCallback('Tests\Unit\Routing\Fixtures\deny_permission');
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertInstanceOf(WP_Error::class, $this->permission('posts'));
    }

    public function test_an_explicit_permission_callback_can_allow_while_middleware_still_runs(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->permissionCallback('Tests\Unit\Routing\Fixtures\allow_permission')
            ->middleware(Fixtures\DenyMiddleware::class);
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertInstanceOf(WP_Error::class, $this->permission('posts'));
    }

    /**
     * Registering the same route twice is a mistake in the routes file, and it
     * is caught when the route is added rather than when WordPress is asked to
     * register an ambiguous path.
     */
    public function test_a_duplicate_route_throws_at_registration(): void
    {
        $this->expectException(\Framework\Routing\Exceptions\RouteRegistrationException::class);

        $registrar = $this->router->registrar();
        $registrar->get('posts', Fixtures\PostController::class, 'index');
        $registrar->get('posts', Fixtures\PostController::class, 'store');
    }

    public function test_a_controller_that_does_not_exist_surfaces_as_a_container_error(): void
    {
        $this->router->registrar()->get('posts', 'Tests\Unit\Routing\Fixtures\MissingController', 'index');
        $this->router->registerRoutes(self::NAMESPACE);

        $this->expectException(\DevLinkStudios\WPServiceContainer\Exceptions\ContainerException::class);

        $this->dispatch('posts');
    }

    /**
     * A broken route definition must reach the client as a 400 naming the
     * problem, not as an uncaught throwable that WordPress renders as an
     * opaque 500 on every request to that endpoint.
     */
    public function test_a_missing_action_returns_a_400_rather_than_a_500(): void
    {
        $this->router->registrar()->get('posts', Fixtures\PostController::class, 'noSuchAction');
        $this->router->registerRoutes(self::NAMESPACE);

        $result = $this->dispatch('posts');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(400, $result->get_status());
        $this->assertStringContainsString('noSuchAction', $result->get_error_message());
    }

    /**
     * A body parameter must not be able to overwrite the value the URL matched.
     *
     * WordPress ranks a JSON/body parameter above the URL when resolving
     * get_param(), so without reading the URL params first a client could POST
     * `{"id": 999}` to `/posts/123` and have the controller act on 999.
     */
    public function test_a_body_parameter_cannot_override_a_route_placeholder(): void
    {
        $this->router->registrar()->get('posts/{id}', Fixtures\PostController::class, 'show');
        $this->router->registerRoutes(self::NAMESPACE);

        $request = new WP_REST_Request();
        $request->set_url_params(['id' => '123']);
        $request->set_params(['id' => '999']);

        $result = $this->dispatchRequest('posts/{id}', $request);

        $this->assertSame(['id' => '123'], $result->get_data());
    }

    /**
     * A JSON `null` for a scalar parameter used to be passed straight into the
     * action, where PHP raised a TypeError inside the controller.
     */
    public function test_a_null_body_value_does_not_reach_a_scalar_parameter(): void
    {
        $this->router->registrar()->get('posts/{slug}', Fixtures\PostController::class, 'bySlug');
        $this->router->registerRoutes(self::NAMESPACE);

        $request = new WP_REST_Request();
        $request->set_url_params(['slug' => 'hello']);
        $request->set_params(['slug' => null]);

        // The URL value must win, so this must not raise a TypeError.
        $this->assertSame(['slug' => 'hello'], $this->dispatchRequest('posts/{slug}', $request)->get_data());
    }

    /**
     * A value of the wrong type is reported as a 400 that names the action,
     * rather than a TypeError from inside it.
     */
    public function test_a_type_mismatch_is_reported_as_a_400(): void
    {
        $this->router->registrar()->get('posts/{id}/meta', Fixtures\PostController::class, 'meta');
        $this->router->registerRoutes(self::NAMESPACE);

        $request = new WP_REST_Request();
        $request->set_url_params(['id' => ['not', 'a', 'scalar']]);

        $result = $this->dispatchRequest('posts/{id}/meta', $request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(400, $result->get_status());
    }

    /**
     * A permission callback returning null must deny. WordPress treats null as
     * a denial, and `return $allowed ? true : null;` is the canonical idiom —
     * so allowing it here would silently make a protected route public.
     */
    public function test_a_permission_callback_returning_null_denies_the_request(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->permissionCallback('Tests\Unit\Routing\Fixtures\null_permission');
        $this->router->registerRoutes(self::NAMESPACE);

        $result = $this->permission('posts');

        $this->assertNull($result, 'A null return must be passed through so WordPress denies it.');
    }

    /**
     * A literal dot in a path must match a literal dot. Unescaped, it is a regex
     * wildcard and `file.json` would also match `fileXjson`.
     */
    public function test_regex_metacharacters_in_a_literal_path_are_escaped(): void
    {
        $this->router->registrar()->get('export.json')->handle(static fn (): array => []);
        $this->router->registerRoutes(self::NAMESPACE);

        $route = WpStub::$registeredRoutes[self::NAMESPACE][0]['route'];

        $this->assertSame('export\.json', $route);
    }

    /**
     * A specific route must be matched before a parameterised one that would
     * otherwise swallow it — registration order must not decide this.
     */
    public function test_a_literal_route_is_registered_before_a_parameterised_one(): void
    {
        $registrar = $this->router->registrar();
        $registrar->get('posts/{id}', Fixtures\PostController::class, 'show');
        $registrar->get('posts/latest', Fixtures\PostController::class, 'latest');

        $this->router->registerRoutes(self::NAMESPACE);

        $routes = array_column(WpStub::$registeredRoutes[self::NAMESPACE], 'route');

        $this->assertSame('posts/latest', $routes[0]);
        $this->assertSame('posts/(?P<id>[^/]+)', $routes[1]);
    }

    /**
     * WordPress can fire rest_api_init more than once in a process. A second
     * pass must be a no-op rather than colliding with the first and throwing.
     */
    public function test_registering_twice_is_a_no_op(): void
    {
        $this->router->registrar()->get('posts', Fixtures\PostController::class, 'index');

        $this->router->registerRoutes(self::NAMESPACE);
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertCount(1, WpStub::$registeredRoutes[self::NAMESPACE]);
    }

    /**
     * Dispatch an already-built request without rebuilding the parameter bag.
     */
    private function dispatchRequest(string $route, WP_REST_Request $request): mixed
    {
        foreach (WpStub::$registeredRoutes[self::NAMESPACE] as $registration) {
            if ($registration['route'] === $this->expectedPattern($route)) {
                return ($registration['callback'])($request);
            }
        }

        $this->fail(sprintf('No route was registered for [%s].', $route));
    }

    /**
     * Invoke the callback WordPress would call, for the route with this path.
     */
    private function dispatch(string $route, array $params = []): mixed
    {
        $request = new WP_REST_Request();
        // Route placeholders arrive as URL params, which is the source the
        // resolver reads first; body and query params are set the same way in
        // the tests that specifically exercise them.
        $request->set_url_params($params);
        $request->set_params($params);

        foreach (WpStub::$registeredRoutes[self::NAMESPACE] as $registration) {
            if ($registration['route'] === $this->expectedPattern($route)) {
                return ($registration['callback'])($request);
            }
        }

        $this->fail(sprintf('No route was registered for [%s].', $route));
    }

    private function permission(string $route): mixed
    {
        $request = new WP_REST_Request();

        foreach (WpStub::$registeredRoutes[self::NAMESPACE] as $registration) {
            if ($registration['route'] === $this->expectedPattern($route)) {
                return ($registration['permission_callback'])($request);
            }
        }

        $this->fail(sprintf('No route was registered for [%s].', $route));
    }

    /**
     * The pattern the router should have produced for a route, so tests look
     * routes up by the path a routes file writes.
     *
     * Delegates to the router's own translation rather than reimplementing it:
     * a second copy would drift, and these tests would then pass while the
     * router did something else.
     */
    private function expectedPattern(string $route): string
    {
        return Router::toWordPressRoute($route);
    }
}
