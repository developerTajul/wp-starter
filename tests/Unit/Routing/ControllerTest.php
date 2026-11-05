<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Routing\Route;
use Framework\Routing\RouteResolver;
use Framework\Routing\Router;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;
use WP_REST_Request;

/**
 * Covers the #[Route] attribute path: reflection over a controller's methods and
 * the routes it produces.
 */
final class ControllerTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        WpStub::reset();

        $container = new Container();
        $this->router = new Router($container, new RouteResolver($container));
    }

    public function test_it_registers_one_route_per_route_attribute(): void
    {
        (new Fixtures\PostController())->registerRoutes($this->router->registrar());

        $paths = array_map(
            static fn (Route $route): string => $route->getPath(),
            $this->router->getRoutes()
        );

        // index, show, store, latest, meta, destroy, update (PUT + PATCH).
        $this->assertContains('posts', $paths);
        $this->assertContains('posts/{id}', $paths);
        $this->assertContains('posts/{id}/meta', $paths);
    }

    /**
     * A repeated attribute on one method registers several verbs against the
     * same action, which is the common PUT/PATCH pairing.
     */
    public function test_a_repeated_attribute_registers_one_route_per_verb(): void
    {
        (new Fixtures\PostController())->registerRoutes($this->router->registrar());

        $verbsForUpdate = [];

        foreach ($this->router->getRoutes() as $route) {
            if ($route->getAction() === 'update') {
                $verbsForUpdate = array_merge($verbsForUpdate, $route->getMethods());
            }
        }

        sort($verbsForUpdate);

        $this->assertSame(['PATCH', 'PUT'], $verbsForUpdate);
    }

    /**
     * The path defaults to the method name, so the usual index/show/store shape
     * needs nothing but the verb in the attribute.
     */
    public function test_a_missing_path_defaults_to_the_method_name(): void
    {
        (new Fixtures\PostController())->registerRoutes($this->router->registrar());

        $latest = $this->routeForAction('latest');

        $this->assertSame('latest', $latest->getPath());
    }

    /**
     * A non-public method is not part of the API surface. Registering it would
     * produce a route that could never be dispatched.
     */
    public function test_non_public_methods_are_not_registered(): void
    {
        (new Fixtures\PostController())->registerRoutes($this->router->registrar());

        foreach ($this->router->getRoutes() as $route) {
            $this->assertNotSame('internal', $route->getAction());
        }
    }

    public function test_the_controller_class_and_action_are_recorded_on_the_route(): void
    {
        (new Fixtures\PostController())->registerRoutes($this->router->registrar());

        $route = $this->routeForAction('show');

        $this->assertSame(Fixtures\PostController::class, $route->getController());
        $this->assertSame('show', $route->getAction());
    }

    /**
     * Middleware declared on the attribute must actually reach the route — this
     * is the wiring that would silently do nothing if the registrar and the
     * router held different Route instances.
     */
    public function test_attribute_middleware_is_attached_to_the_route(): void
    {
        (new Fixtures\PostController())->registerRoutes($this->router->registrar());

        $this->assertSame(
            [Fixtures\AllowMiddleware::class],
            $this->routeForAction('destroy')->getMiddleware()
        );
    }

    /**
     * A controller registers into whatever registrar it is given, so a group's
     * prefix and middleware apply to attribute-declared routes too. Without
     * this, mounting a controller inside a protected group would leave its
     * routes unprotected.
     */
    public function test_attribute_routes_inherit_the_enclosing_group_prefix_and_middleware(): void
    {
        $registrar = $this->router->registrar();

        $registrar->group(
            ['prefix' => 'admin', 'middleware' => [Fixtures\DenyMiddleware::class]],
            static function ($registrar): void {
                (new Fixtures\PostController())->registerRoutes($registrar);
            }
        );

        $route = $this->routeForAction('index');

        $this->assertSame('admin/posts', $route->getPath());
        $this->assertSame([Fixtures\DenyMiddleware::class], $route->getMiddleware());
    }

    /**
     * Attribute middleware is appended after any group middleware, so a group's
     * broad check runs before a route's narrower one.
     */
    public function test_attribute_middleware_appends_after_group_middleware(): void
    {
        $registrar = $this->router->registrar();

        $registrar->group(
            ['middleware' => [Fixtures\DenyMiddleware::class]],
            static function ($registrar): void {
                (new Fixtures\PostController())->registerRoutes($registrar);
            }
        );

        $this->assertSame(
            [Fixtures\DenyMiddleware::class, Fixtures\AllowMiddleware::class],
            $this->routeForAction('destroy')->getMiddleware()
        );
    }

    /**
     * An attribute-declared route must be dispatchable end to end, not merely
     * present in the route table.
     */
    public function test_an_attribute_route_dispatches(): void
    {
        (new Fixtures\PostController())->registerRoutes($this->router->registrar());
        $this->router->registerRoutes('test/v1');

        $request = new WP_REST_Request();
        $request->set_params(["id" => "9"]);
        $request->set_url_params(["id" => "9"]);

        $response = null;

        foreach (WpStub::$registeredRoutes['test/v1'] as $registration) {
            if ($registration['methods'] === ['GET'] && $registration['route'] === 'posts/(?P<id>[^/]+)') {
                $response = ($registration['callback'])($request);
            }
        }

        $this->assertNotNull($response, 'The attribute-declared show route was not registered.');
        $this->assertSame(['id' => '9'], $response->get_data());
    }

    /**
     * The base Controller's response helpers set the status a caller reads back.
     */
    public function test_the_response_helpers_set_the_status_code(): void
    {
        $controller = new Fixtures\PostController();

        $this->assertSame(200, $controller->ok()->get_status());
        $this->assertSame(201, $controller->made()->get_status());
        $this->assertSame(204, $controller->empty()->get_status());
    }

    private function routeForAction(string $action): Route
    {
        foreach ($this->router->getRoutes() as $route) {
            if ($route->getAction() === $action) {
                return $route;
            }
        }

        $this->fail(sprintf('No route was registered for action [%s].', $action));
    }
}
