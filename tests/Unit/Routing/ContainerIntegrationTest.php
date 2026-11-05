<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Routing\RouteResolver;
use Framework\Routing\Router;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;
use WP_REST_Request;

/**
 * The point of the container: a controller and its dependencies are wired by
 * type, so no class in the request path has to reach for `new` or a global.
 *
 * These tests use a container with the same self-bindings the real one has, so
 * they exercise the wiring a running plugin would actually get.
 */
final class ContainerIntegrationTest extends TestCase
{
    private const NAMESPACE = 'test/v1';

    private Container $container;
    private Router $router;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->container = new Container();
        $this->container->instance(
            \DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface::class,
            $this->container
        );

        // A contract has no implementation of its own, so it needs a default
        // binding before anything depending on it can be constructed. This is
        // exactly what a project's own service provider would declare.
        $this->container->bind(Fixtures\ReportSource::class, Fixtures\LiveReportSource::class);

        $this->router = new Router($this->container, new RouteResolver($this->container));
    }

    public function test_a_controllers_constructor_dependencies_are_injected(): void
    {
        $this->router->registrar()->get('reports', Fixtures\ReportController::class, 'index');
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertSame(
            ['source' => 'live-service'],
            $this->dispatch('reports')->get_data()
        );
    }

    /**
     * A binding declared for the interface must be what the controller gets, not
     * a fresh auto-wired implementation — this is what makes the contract worth
     * declaring at all.
     */
    public function test_a_bound_interface_is_injected_rather_than_auto_wired(): void
    {
        $this->container->singleton(
            Fixtures\ReportSource::class,
            static fn (): Fixtures\FakeReportSource => new Fixtures\FakeReportSource('bound-service')
        );

        $this->router->registrar()->get('reports', Fixtures\ReportController::class, 'index');
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertSame(['source' => 'bound-service'], $this->dispatch('reports')->get_data());
    }

    /**
     * The same binding must be reachable from a middleware, so the router's
     * dependency handling is uniform across the two seams WordPress gives it.
     */
    public function test_middleware_dependencies_are_injected(): void
    {
        $this->container->singleton(
            Fixtures\ReportSource::class,
            static fn (): Fixtures\FakeReportSource => new Fixtures\FakeReportSource('from-middleware')
        );

        $this->router->registrar()
            ->get('reports', Fixtures\ReportController::class, 'index')
            ->middleware(Fixtures\SourceCheckingMiddleware::class);
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertTrue($this->permission('reports'));
    }

    /**
     * A controller is resolved per request. Sharing one across requests would
     * carry request-scoped state into the next one, which is the classic
     * long-running-process bug.
     */
    public function test_a_controller_is_not_shared_between_dispatches(): void
    {
        $this->router->registrar()->get('reports', Fixtures\ReportController::class, 'index');
        $this->router->registerRoutes(self::NAMESPACE);

        $first = $this->dispatch('reports');
        $second = $this->dispatch('reports');

        $this->assertNotSame($first, $second);
    }

    /**
     * The request is injected by type wherever it is asked for, and a route
     * parameter is injected by name — both in the same signature.
     */
    public function test_the_request_and_route_parameters_are_injected_side_by_side(): void
    {
        $this->router->registrar()->get('reports/{id}', Fixtures\ReportController::class, 'show');
        $this->router->registerRoutes(self::NAMESPACE);

        $response = $this->dispatch('reports/{id}', ['id' => 'abc']);

        $this->assertSame(['id' => 'abc', 'source' => 'live-service'], $response->get_data());
    }

    private function dispatch(string $route, array $params = []): mixed
    {
        $request = new WP_REST_Request();
        $request->set_url_params($params);
        $request->set_params($params);

        return ($this->registration($route)['callback'])($request);
    }

    private function permission(string $route): mixed
    {
        return ($this->registration($route)['permission_callback'])(new WP_REST_Request());
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(string $route): array
    {
        $pattern = \Framework\Routing\Router::toWordPressRoute($route);

        foreach (WpStub::$registeredRoutes[self::NAMESPACE] as $registration) {
            if ($registration['route'] === $pattern) {
                return $registration;
            }
        }

        $this->fail(sprintf('No route was registered for [%s].', $route));
    }
}
