<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Http\Request;
use Framework\Routing\RouteResolver;
use Framework\Routing\Router;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;
use Tests\Unit\Routing\Fixtures\ConstructorRequestController;
use Tests\Unit\Routing\Fixtures\HttpRequestController;
use WP_REST_Request;

/**
 * A controller type-hinting the framework's `Request` must receive the request
 * WordPress actually dispatched, not the superglobal capture taken at plugin
 * load.
 *
 * That capture is the failure this pins. On a REST request WordPress has already
 * consumed `php://input` and matched the URL itself, so an instance built from
 * `$_GET`/`$_POST` carries neither the JSON body nor the route parameters — and
 * it fails *silently*, answering `null` for every read rather than erroring.
 */
final class HttpRequestBindingTest extends TestCase
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

    public function test_a_controller_receives_the_url_matched_parameters(): void
    {
        $this->register('GET', 'leads/{id}', HttpRequestController::class, 'show');

        $request = new WP_REST_Request();
        $request->set_url_params(['id' => '123']);
        $request->set_params(['id' => '123']);
        $request->set_query_params(['filter' => 'recent']);

        $data = $this->dispatch('leads/{id}', $request)->get_data();

        $this->assertSame('123', $data['id']);
        $this->assertSame('recent', $data['filter']);
    }

    /**
     * The JSON body is the case a superglobal capture can never satisfy on a REST
     * request, so a passing assertion here is only explicable if the binding
     * worked.
     */
    public function test_a_controller_receives_the_json_body(): void
    {
        $this->register('POST', 'leads', HttpRequestController::class, 'store');

        $request = new WP_REST_Request();
        $request->set_method('POST');
        $request->set_header('content-type', 'application/json');
        $request->set_body('{"email":"a@b.test","meta":{"plan":"pro"}}');

        $data = $this->dispatch('leads', $request)->get_data();

        $this->assertSame('a@b.test', $data['email']);
        $this->assertSame('pro', $data['plan']);
        $this->assertTrue($data['isJson']);
    }

    /**
     * The constraint from the brief: a controller hinting the native
     * `WP_REST_Request` must keep working, and both objects must describe the
     * same request when a controller asks for both.
     */
    public function test_both_request_objects_describe_the_same_request(): void
    {
        $this->register('GET', 'leads/{id}/both', HttpRequestController::class, 'both');

        $request = new WP_REST_Request();
        $request->set_url_params(['id' => '77']);
        $request->set_params(['id' => '77']);

        $data = $this->dispatch('leads/{id}/both', $request)->get_data();

        $this->assertSame('77', $data['framework']);
        $this->assertSame('77', $data['native']);
    }

    /**
     * A constructor dependency is resolved when the controller is built, which
     * happens inside the dispatch — so this fails if the binding is installed
     * after the controller is resolved rather than before.
     */
    public function test_a_construct_injected_request_is_the_dispatched_one(): void
    {
        $this->register('GET', 'leads/from-constructor', ConstructorRequestController::class, 'show');

        $request = new WP_REST_Request();
        $request->set_url_params(['id' => '555']);
        $request->set_params(['id' => '555']);
        $request->set_query_params(['filter' => 'mine']);

        $data = $this->dispatch('leads/from-constructor', $request)->get_data();

        $this->assertSame('555', $data['id']);
        $this->assertSame('mine', $data['filter']);
    }

    /**
     * The binding is scoped to the dispatch. If it outlived one, the next
     * request to touch the container would receive the previous request's data —
     * the failure mode a persistent worker makes routine.
     */
    public function test_the_binding_does_not_outlive_the_dispatch(): void
    {
        $this->register('GET', 'leads/{id}', HttpRequestController::class, 'show');

        $first = new WP_REST_Request();
        $first->set_url_params(['id' => 'first']);
        $first->set_params(['id' => 'first']);

        $this->assertSame('first', $this->dispatch('leads/{id}', $first)->get_data()['id']);

        // No binding was in place before the dispatch, so none must be left after
        // it — resolving now would otherwise hand back the finished request.
        $this->assertFalse(
            $this->container->bound(Request::class),
            'The dispatch must not leave Framework\Http\Request bound behind it.'
        );
    }

    /**
     * When something *had* already bound a Request — `HttpServiceProvider` does,
     * with the superglobal capture — the dispatch must put it back rather than
     * forget it, or later non-REST code would find the binding missing.
     */
    public function test_a_pre_existing_binding_is_restored_after_the_dispatch(): void
    {
        $original = new Request(query: ['from' => 'superglobals']);
        $this->container->instance(Request::class, $original);

        $this->register('GET', 'leads/{id}', HttpRequestController::class, 'show');

        $request = new WP_REST_Request();
        $request->set_url_params(['id' => '123']);
        $request->set_params(['id' => '123']);

        $this->dispatch('leads/{id}', $request);

        $this->assertSame(
            $original,
            $this->container->resolve(Request::class),
            'The dispatch must restore the binding that was there before it.'
        );
    }

    /**
     * Routing must not depend on the HTTP layer being enabled: a consuming
     * project that drops `HttpServiceProvider` still gets working routes, with
     * the native request object and no binding side effects.
     */
    public function test_routing_works_with_no_http_binding_at_all(): void
    {
        $this->register('GET', 'posts/{id}', Fixtures\PostController::class, 'show');

        $request = new WP_REST_Request();
        $request->set_url_params(['id' => '9']);
        $request->set_params(['id' => '9']);

        $data = $this->dispatch('posts/{id}', $request)->get_data();

        $this->assertSame('9', $data['id']);
        $this->assertFalse($this->container->bound(Request::class));
    }

    /**
     * A closure handler sees the same binding a controller does.
     *
     * `dispatch()` binds before either branch, so a `function (WP_REST_Request
     * $r)` route — which cannot receive the framework Request as a parameter, as
     * it is not the object WordPress passes — can still reach it through the
     * container. Pinned because that path is easy to overlook when the binding is
     * only ever reasoned about for controllers.
     */
    public function test_a_closure_handler_can_reach_the_dispatched_request(): void
    {
        $seen = null;
        $container = $this->container;

        $this->router->registrar()->get('probe/{id}', static function () use (&$seen, $container): \WP_REST_Response {
            // Resolved from the router's own container, which is where the
            // binding is installed. `app()` keeps a *separate* static container,
            // so in this suite it would be a different one and return nothing —
            // in production RouteServiceProvider hands the router the same
            // instance app() returns, so the two agree.
            $seen = $container->resolve(Request::class);

            return new \WP_REST_Response([], 200);
        });
        $this->router->registerRoutes(self::NAMESPACE);

        $request = new WP_REST_Request();
        $request->set_url_params(['id' => '888']);
        $request->set_params(['id' => '888']);

        $this->dispatch('probe/{id}', $request);

        $this->assertInstanceOf(Request::class, $seen);
        $this->assertSame(
            '888',
            $seen->input('id'),
            'A closure handler must see the dispatched request, not a stale capture.'
        );
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @param class-string $controller
     */
    private function register(string $method, string $path, string $controller, string $action): void
    {
        $this->router->registrar()->match([$method], $path, $controller, $action);
        $this->router->registerRoutes(self::NAMESPACE);
    }

    private function dispatch(string $route, WP_REST_Request $request): mixed
    {
        $pattern = $this->router::toWordPressRoute($route);

        foreach (WpStub::$registeredRoutes[self::NAMESPACE] as $registration) {
            if ($registration['route'] === $pattern) {
                return ($registration['callback'])($request);
            }
        }

        $this->fail(sprintf('No route was registered for [%s].', $route));
    }
}
