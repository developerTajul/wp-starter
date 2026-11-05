<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Http\Request;
use Framework\Routing\Adapters\RestApiRouterAdapter;
use Framework\Routing\Exceptions\RouteRegistrationException;
use Framework\Routing\RouteResolver;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;
use WP_Error;
use WP_REST_Request;

/**
 * The REST adapter translates Route objects into `register_rest_route()` calls.
 *
 * ## Why this exists alongside RouterTest
 *
 * `RestApiRouterAdapter` is a port of `Framework\Routing\Router` behind the shared
 * {@see \Framework\Routing\Contracts\RouterInterface}, and the behaviour is meant
 * to be identical. Identical is exactly what goes wrong in a port — a
 * permission-callback precedence rule simplified while "cleaning up", a
 * middleware failure path that stops failing closed — and none of it is visible
 * from a happy-path test. So the assertions here are the ones that pin the
 * security-relevant behaviour rather than the endpoints.
 *
 * A regression in the shared {@see \Framework\Routing\ParameterBinder}, which both
 * domains now use, would show up in either suite; it is pinned directly in
 * {@see ParameterBinderTest}.
 */
final class RestApiRouterAdapterTest extends TestCase
{
    private const NAMESPACE = 'test/v1';

    private Container $container;
    private RestApiRouterAdapter $router;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->container = new Container();
        $this->container->instance(
            \DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface::class,
            $this->container
        );

        $this->container->instance(Request::class, Request::capture());

        $this->router = new RestApiRouterAdapter(
            $this->container,
            new RouteResolver($this->container)
        );

        // An explicit namespace rather than a config write. Config is a static
        // store with no setter, and mutating a global to control one test is how a
        // suite acquires an order dependency — a later test would inherit this
        // namespace and resolve endpoints it never registered.
        $this->router->publishUnder(self::NAMESPACE);
    }

    // ----------------------------------------------------------- registration

    public function test_a_route_becomes_a_registered_rest_route(): void
    {
        $this->router->get('leads', Fixtures\ReportController::class, 'index');
        $this->router->flush();

        $registered = WpStub::$registeredRoutes[self::NAMESPACE] ?? [];

        $this->assertCount(1, $registered);
        $this->assertSame('leads', $registered[0]['route']);
        $this->assertSame(['GET'], $registered[0]['methods']);
    }

    /**
     * The path has to go through the shared compiler, or a placeholder would mean
     * one thing here and another on a web route — the drift the compiler exists to
     * prevent.
     */
    public function test_a_placeholder_is_translated_by_the_shared_compiler(): void
    {
        $this->router->get('posts/{id:\d+}', Fixtures\ReportController::class, 'index');
        $this->router->flush();

        $this->assertSame(
            'posts/(?P<id>\d+)',
            WpStub::$registeredRoutes[self::NAMESPACE][0]['route']
        );
    }

    /**
     * WordPress matches registered patterns in order and takes the first, so a
     * literal route must be registered ahead of a parameterised sibling.
     */
    public function test_a_literal_route_is_registered_before_a_parameterised_one(): void
    {
        $this->router->get('posts/{id}', Fixtures\ReportController::class, 'index');
        $this->router->get('posts/latest', Fixtures\ReportController::class, 'index');

        $this->router->flush();

        $paths = array_column(WpStub::$registeredRoutes[self::NAMESPACE], 'route');

        $this->assertSame(['posts/latest', 'posts/(?P<id>[^/]+)'], $paths);
    }

    /**
     * Nothing is handed to WordPress before flush() — that is what "collect then
     * publish" means, and asserting after would pass for an adapter that registered
     * eagerly.
     */
    public function test_nothing_is_registered_before_flushing(): void
    {
        $this->router->get('leads', Fixtures\ReportController::class, 'index');

        $this->assertSame([], WpStub::$registeredRoutes);
        $this->assertFalse($this->router->hasFlushed());
    }

    public function test_flushing_twice_registers_once(): void
    {
        $this->router->get('leads', Fixtures\ReportController::class, 'index');

        $this->router->flush();
        $this->router->flush();

        $this->assertCount(1, WpStub::$registeredRoutes[self::NAMESPACE]);
    }

    public function test_a_route_with_no_handler_is_refused(): void
    {
        $this->router->getRoutes();
        $this->router->registrar()->get('leads');

        $this->expectException(RouteRegistrationException::class);
        $this->expectExceptionMessageMatches('/has no handler/');

        $this->router->flush();
    }

    /**
     * A bad route must not leave the set half-published. WordPress has already
     * accepted the earlier registrations by the time a throw would escape, so a
     * handler-less route declared last would silently cost every route before it.
     */
    public function test_a_bad_route_prevents_any_registration(): void
    {
        $this->router->get('leads', Fixtures\ReportController::class, 'index');
        $this->router->registrar()->get('broken');

        try {
            $this->router->flush();
        } catch (RouteRegistrationException) {
            // Expected.
        }

        $this->assertSame(
            [],
            WpStub::$registeredRoutes[self::NAMESPACE] ?? [],
            'Validation must complete before the first register_rest_route() call.'
        );
    }

    // ------------------------------------------------------- permission callback

    /**
     * Every route carries a permission callback. WordPress warns on a missing one,
     * and a route that ends up public by omission is a security bug rather than a
     * convenience.
     */
    public function test_every_route_registers_a_permission_callback(): void
    {
        $this->router->get('leads', Fixtures\ReportController::class, 'index');
        $this->router->flush();

        $this->assertIsCallable(
            WpStub::$registeredRoutes[self::NAMESPACE][0]['permission_callback']
        );
    }

    /**
     * A middleware that denies produces a WP_Error, which is what WordPress needs
     * to render a proper REST error rather than a 200 with the wrong body.
     */
    public function test_denying_middleware_blocks_the_route(): void
    {
        $this->router->get('leads', Fixtures\ReportController::class, 'index')
            ->middleware(Fixtures\DenyMiddleware::class);

        $this->router->flush();

        $result = $this->permissionCallback(0)(new WP_REST_Request());

        $this->assertInstanceOf(WP_Error::class, $result);
    }

    /**
     * A middleware that allows must produce exactly `true`. Returning a truthy
     * value instead — the middleware's own null, or a WP_Error from a stale
     * check — would either deny a legitimate request or let WordPress see an
     * object it does not understand.
     */
    public function test_allowance_returns_true(): void
    {
        $this->router->get('leads', Fixtures\ReportController::class, 'index')
            ->middleware(Fixtures\AllowMiddleware::class);

        $this->router->flush();

        $this->assertTrue($this->permissionCallback(0)(new WP_REST_Request()));
    }

    /**
     * A middleware that throws must fail **closed**. WordPress calls the permission
     * callback with no error handling of its own, so an escaping throwable is an
     * unhandled fatal that any anonymous caller can trigger on demand — and a
     * check that could not run has authorised nothing.
     */
    public function test_a_throwing_middleware_fails_closed(): void
    {
        $this->router->get('leads', Fixtures\ReportController::class, 'index')
            ->middleware(Fixtures\ThrowingMiddleware::class);

        $this->router->flush();

        $result = $this->permissionCallback(0)(new WP_REST_Request());

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(401, $result->get_error_data()['status']);
    }

    /**
     * A middleware returning something other than a WP_Error or null is a contract
     * violation, and a truthy result must not be read as "allowed".
     */
    public function test_a_middleware_violating_its_contract_is_refused(): void
    {
        $this->router->get('leads', Fixtures\ReportController::class, 'index')
            ->middleware(Fixtures\LooseMiddleware::class);

        $this->router->flush();

        $result = $this->permissionCallback(0)(new WP_REST_Request());

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(500, $result->get_error_data()['status']);
    }

    // ------------------------------------------------------------- the domain

    public function test_the_domain_is_api(): void
    {
        $this->assertSame('api', $this->router->domain());
    }

    public function test_the_namespace_comes_from_config(): void
    {
        $this->assertSame(self::NAMESPACE, $this->router->namespace());
    }

    public function test_the_adapter_satisfies_the_router_contract(): void
    {
        $this->assertInstanceOf(
            \Framework\Routing\Contracts\RouterInterface::class,
            $this->router
        );
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @return callable(WP_REST_Request): (WP_Error|bool|null)
     */
    private function permissionCallback(int $index): callable
    {
        return WpStub::$registeredRoutes[self::NAMESPACE][$index]['permission_callback'];
    }
}
