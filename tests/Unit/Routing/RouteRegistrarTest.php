<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Routing\Exceptions\RouteRegistrationException;
use Framework\Routing\Route;
use Framework\Routing\RouteRegistrar;
use Framework\Routing\RouteResolver;
use Framework\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouteRegistrarTest extends TestCase
{
    private Router $router;
    private RouteRegistrar $registrar;

    protected function setUp(): void
    {
        $container = new Container();
        $this->router = new Router($container, new RouteResolver($container));
        $this->registrar = $this->router->registrar();
    }

    public function test_it_registers_a_route_for_its_verb(): void
    {
        $this->registrar->get('posts', Fixtures\PostController::class, 'index');

        $routes = $this->router->getRoutes();

        $this->assertCount(1, $routes);
        $this->assertSame(['GET'], $routes[0]->getMethods());
        $this->assertSame('posts', $routes[0]->getPath());
        $this->assertSame(Fixtures\PostController::class, $routes[0]->getController());
        $this->assertSame('index', $routes[0]->getAction());
    }

    /**
     * A path given with a leading or trailing slash must normalize to the same
     * route. Otherwise `/posts` and `posts` would register as two different
     * routes and the duplicate guard below would not catch the collision.
     */
    public function test_it_normalizes_surrounding_slashes_in_the_path(): void
    {
        $this->registrar->get('/posts');

        $this->assertSame('posts', $this->router->getRoutes()[0]->getPath());
    }

    public function test_each_verb_registers_its_own_method(): void
    {
        $this->registrar->get('a');
        $this->registrar->post('b');
        $this->registrar->put('c');
        $this->registrar->patch('d');
        $this->registrar->delete('e');

        $methods = array_map(
            static fn (Route $route): string => $route->getMethods()[0],
            $this->router->getRoutes()
        );

        $this->assertSame(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $methods);
    }

    public function test_an_unsupported_verb_is_rejected(): void
    {
        $this->expectException(RouteRegistrationException::class);
        $this->expectExceptionMessageMatches('/Unsupported HTTP method \[HEAD\]/');

        $this->registrar->match(['HEAD'], 'posts');
    }

    /**
     * A route is stored the moment it is created so it can still be configured
     * fluently; the handler check moves to registration, which is the last point
     * at which a missing handler can still be reported usefully.
     */
    public function test_a_route_without_a_handler_is_rejected_at_registration(): void
    {
        $this->registrar->get('posts');

        $this->expectException(RouteRegistrationException::class);
        $this->expectExceptionMessageMatches('/has no handler/');

        $this->router->registerRoutes('test/v1');
    }

    public function test_a_duplicate_method_and_path_is_rejected(): void
    {
        $this->registrar->get('posts', Fixtures\PostController::class, 'index');

        $this->expectException(RouteRegistrationException::class);
        $this->expectExceptionMessageMatches('/already registered/');

        $this->registrar->get('posts', Fixtures\PostController::class, 'other');
    }

    /**
     * The same path under different verbs is not a collision — that is the
     * entire point of a REST resource.
     */
    public function test_the_same_path_on_different_verbs_is_allowed(): void
    {
        $this->registrar->get('posts');
        $this->registrar->post('posts');

        $this->assertCount(2, $this->router->getRoutes());
    }

    public function test_a_group_prefix_is_applied_to_its_routes(): void
    {
        $this->registrar->group(['prefix' => 'api'], function (RouteRegistrar $router): void {
            $router->get('posts');
        });

        $this->assertSame('api/posts', $this->router->getRoutes()[0]->getPath());
    }

    public function test_nested_group_prefixes_accumulate(): void
    {
        $this->registrar->group(['prefix' => 'api'], function (RouteRegistrar $router): void {
            $router->group(['prefix' => 'v1'], function (RouteRegistrar $router): void {
                $router->get('posts');
            });
        });

        $this->assertSame('api/v1/posts', $this->router->getRoutes()[0]->getPath());
    }

    /**
     * A group's prefix must not leak into routes declared after it closes, or
     * every subsequent route silently acquires a path nobody wrote.
     */
    public function test_a_group_prefix_does_not_leak_past_the_group(): void
    {
        $this->registrar->group(['prefix' => 'api'], function (RouteRegistrar $router): void {
            $router->get('inside');
        });
        $this->registrar->get('outside');

        $paths = array_map(
            static fn (Route $route): string => $route->getPath(),
            $this->router->getRoutes()
        );

        $this->assertSame(['api/inside', 'outside'], $paths);
    }

    /**
     * The restore happens in a `finally`. A group callback that throws must
     * still leave the registrar usable — otherwise one bad group poisons every
     * route declared after it in the same file.
     */
    public function test_a_group_prefix_is_restored_even_when_the_callback_throws(): void
    {
        try {
            $this->registrar->group(['prefix' => 'api'], static function (): void {
                throw new \RuntimeException('routes file blew up');
            });
        } catch (\RuntimeException) {
            // Expected; the assertion is about the state left behind.
        }

        $this->registrar->get('outside');

        $this->assertSame('outside', $this->router->getRoutes()[0]->getPath());
    }

    /**
     * **A group's `as` contributes a name prefix.**
     *
     * The spelling `Route::group(['as' => 'leads.'], ...)` is Laravel's, and what it
     * buys is that the name inside the group is *relative*: `->name('index')`
     * produces `leads.index`. So the prefix has to be applied when the author names
     * the route, not before — the route is unnamed at the moment `route()` returns
     * it, and `Route::name()` replaces rather than appends.
     */
    public function test_a_group_name_prefix_is_applied_to_its_routes(): void
    {
        $this->registrar->group(['as' => 'leads.'], function (RouteRegistrar $router): void {
            $router->get('leads')->name('index');
        });

        $this->assertSame('leads.index', $this->router->getRoutes()[0]->getName());
    }

    /**
     * The path prefix and the name prefix are independent.
     *
     * `['prefix' => 'leads', 'as' => 'leads.']` is the ordinary pairing, but
     * deriving one from the other would break the two cases where they differ: a
     * project grouping names without grouping paths, or grouping paths without
     * grouping names. Asserted with both set to different values, so a rule that
     * wired one to the other produces the wrong string rather than a coincidentally
     * right one.
     */
    public function test_the_name_prefix_is_independent_of_the_path_prefix(): void
    {
        $this->registrar->group(['prefix' => 'admin/leads', 'as' => 'lead.'], function (RouteRegistrar $router): void {
            $router->get('index')->name('index');
        });

        $route = $this->router->getRoutes()[0];

        $this->assertSame('admin/leads/index', $route->getPath());
        $this->assertSame('lead.index', $route->getName());
    }

    /**
     * Nested `as` prefixes concatenate, outer first.
     *
     * The same rule as nested path prefixes, and the reason the inner group
     * *prepends* to the outer rather than replacing it: `admin.` around `leads.`
     * reads as `admin.leads.`, which is what a file nesting an admin group around
     * a resource group means.
     */
    public function test_nested_group_name_prefixes_accumulate(): void
    {
        $this->registrar->group(['as' => 'admin.'], function (RouteRegistrar $router): void {
            $router->group(['as' => 'leads.'], function (RouteRegistrar $router): void {
                $router->get('leads')->name('index');
            });
        });

        $this->assertSame('admin.leads.index', $this->router->getRoutes()[0]->getName());
    }

    /**
     * A group's name prefix must not leak into routes declared after it closes.
     *
     * The failure is quiet and symmetric with the path-prefix case: an unnamed
     * route is harmless, but a route named `outside` after `['as' => 'leads.']`
     * closing would be reachable as `leads.outside` — a name no `route()` call in
     * the project would ever look up.
     */
    public function test_a_group_name_prefix_does_not_leak_past_the_group(): void
    {
        $this->registrar->group(['as' => 'leads.'], function (RouteRegistrar $router): void {
            $router->get('inside')->name('inside');
        });
        $this->registrar->get('outside')->name('outside');

        $names = array_map(
            static fn (Route $route): ?string => $route->getName(),
            $this->router->getRoutes()
        );

        $this->assertSame(['leads.inside', 'outside'], $names);
    }

    /**
     * Closing the *inner* group restores the outer prefix, not the empty one.
     *
     * The case a single saved/restored value gets wrong: if entering a nested group
     * overwrote the previous prefix instead of the running concatenation being
     * saved, the route after the inner group would lose `admin.` and only the
     * innermost prefix would ever be restored.
     */
    public function test_closing_a_nested_group_restores_the_outer_name_prefix(): void
    {
        $this->registrar->group(['as' => 'admin.'], function (RouteRegistrar $router): void {
            $router->group(['as' => 'leads.'], function (RouteRegistrar $router): void {
                $router->get('a')->name('inner');
            });

            $router->get('b')->name('outer');
        });

        $names = array_map(
            static fn (Route $route): ?string => $route->getName(),
            $this->router->getRoutes()
        );

        $this->assertSame(['admin.leads.inner', 'admin.outer'], $names);
    }

    /**
     * The restore happens in a `finally`, for the name prefix too.
     *
     * Mirrors the path-prefix case: a callback that throws must not leave the
     * registrar aimed at a group, or one bad group renames every route after it.
     */
    public function test_a_group_name_prefix_is_restored_even_when_the_callback_throws(): void
    {
        try {
            $this->registrar->group(['as' => 'leads.'], static function (): void {
                throw new \RuntimeException('routes file blew up');
            });
        } catch (\RuntimeException) {
            // Expected; the assertion is about the state left behind.
        }

        $this->registrar->get('outside')->name('outside');

        $this->assertSame('outside', $this->router->getRoutes()[0]->getName());
    }

    /**
     * A route inside a group may be left unnamed without being given a bare prefix.
     *
     * `->name()` is optional, and the failure a naive implementation produces is a
     * route whose name is the prefix alone — `"leads."` — which then collides with
     * every other unnamed route in the same group and reports a spurious duplicate.
     */
    public function test_an_unnamed_route_in_a_group_stays_unnamed(): void
    {
        $this->registrar->group(['as' => 'leads.'], function (RouteRegistrar $router): void {
            $router->get('leads');
            $router->get('leads-create');
        });

        $names = array_map(
            static fn (Route $route): ?string => $route->getName(),
            $this->router->getRoutes()
        );

        $this->assertSame([null, null], $names);
    }

    public function test_group_middleware_is_applied_to_its_routes(): void
    {
        $this->registrar->group(
            ['middleware' => [Fixtures\AllowMiddleware::class]],
            function (RouteRegistrar $router): void {
                $router->get('posts');
            }
        );

        $this->assertSame(
            [Fixtures\AllowMiddleware::class],
            $this->router->getRoutes()[0]->getMiddleware()
        );
    }

    /**
     * Group middleware applies first, then route middleware. Order is load
     * bearing: the first middleware to return a WP_Error wins, so the broadest
     * check has to run before the narrowest.
     */
    public function test_route_middleware_appends_after_group_middleware(): void
    {
        $this->registrar->group(
            ['middleware' => [Fixtures\AllowMiddleware::class]],
            function (RouteRegistrar $router): void {
                $router->get('posts')->middleware(Fixtures\DenyMiddleware::class);
            }
        );

        $this->assertSame(
            [Fixtures\AllowMiddleware::class, Fixtures\DenyMiddleware::class],
            $this->router->getRoutes()[0]->getMiddleware()
        );
    }

    public function test_a_route_created_fluently_is_returned_for_further_configuration(): void
    {
        $route = $this->registrar->get('posts', Fixtures\PostController::class, 'index');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('index', $route->getAction());
    }

    public function test_a_fluent_route_can_carry_an_explicit_permission_callback(): void
    {
        $this->registrar->get('posts')->permissionCallback('my_permission_check');

        $this->assertSame('my_permission_check', $this->router->getRoutes()[0]->getPermissionCallback());
    }

    public function test_a_closure_handler_is_accepted(): void
    {
        $this->registrar->get('ping')->handle(static fn (): array => ['pong' => true]);

        $route = $this->router->getRoutes()[0];

        $this->assertNotNull($route->getHandler());
        $this->assertNull($route->getController());
    }

    public function test_match_registers_one_route_per_verb(): void
    {
        $this->registrar->match(['GET', 'POST'], 'posts', Fixtures\PostController::class, 'index');

        $this->assertCount(2, $this->router->getRoutes());
    }

    public function test_any_registers_one_route_per_supported_verb(): void
    {
        $this->registrar->any('posts', Fixtures\PostController::class, 'index');

        $this->assertCount(count(RouteRegistrar::SUPPORTED_METHODS), $this->router->getRoutes());
    }

    /**
     * Route mutators apply in place and return the same instance. This is a
     * contract, not an implementation detail: the fluent form hands the caller
     * the route the Router stored, so a clone-on-write mutator would let a
     * caller configure a route that is never the one that gets registered.
     */
    public function test_route_mutators_apply_in_place_and_return_the_same_instance(): void
    {
        $route = Route::make(['GET'], 'posts');

        $this->assertSame($route, $route->uses(Fixtures\PostController::class, 'index'));
        $this->assertSame($route, $route->middleware(Fixtures\AllowMiddleware::class));
        $this->assertSame(Fixtures\PostController::class, $route->getController());
        $this->assertSame([Fixtures\AllowMiddleware::class], $route->getMiddleware());
    }

    /**
     * The registered route and the returned route must be one object, or the
     * declarative attribute path would register routes whose middleware and
     * permission callbacks were applied to a discarded copy.
     */
    public function test_the_route_returned_by_the_registrar_is_the_one_registered(): void
    {
        $route = $this->registrar->get('posts', Fixtures\PostController::class, 'index');
        $route->middleware(Fixtures\AllowMiddleware::class);

        $this->assertSame($route, $this->router->getRoutes()[0]);
        $this->assertSame([Fixtures\AllowMiddleware::class], $this->router->getRoutes()[0]->getMiddleware());
    }

    public function test_an_empty_route_path_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Route::make(['GET'], '   ');
    }
}
