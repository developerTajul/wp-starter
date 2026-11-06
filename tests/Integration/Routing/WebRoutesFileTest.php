<?php
declare(strict_types=1);

namespace Tests\Integration\Routing;

use App\Http\Controllers\Admin\LeadController;
use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use Framework\Http\HttpServiceProvider;
use Framework\Http\Middleware\Authenticate;
use Framework\Http\Middleware\ConvertEmptyStringsToNull;
use Framework\Http\Middleware\SanitizeInput;
use Framework\Http\Middleware\TrimStrings;
use Framework\Routing\Adapters\RestApiRouterAdapter;
use Framework\Routing\Adapters\WordPressRouterAdapter;
use Framework\Routing\CaptureWebRequestFactory;
use Framework\Routing\RouteLoader;
use Framework\Routing\RouteRegistrar;
use Framework\Routing\RouteResolver;
use Framework\Routing\WebRouteRegistrar;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

/**
 * A web routes file reaches the router through the loader, and publishes.
 *
 * ## Why this test exists
 *
 * Every other routing test hands the adapter its routes directly —
 * `$this->router->adminPage(...)`, then `flush()`. That is the adapter's own
 * contract and it is well covered. What none of them covered is the **seam**
 * between the two halves of the design: `RouteLoader` gives a routes file
 * `$router->registrar()`, while a web route needs the adapter's surface helpers,
 * which the plain registrar does not have.
 *
 * For the whole life of the web surface, those two facts were contradictory, and
 * nothing noticed. The shipped `routes/web.php` died on a `TypeError` the moment
 * it was `require`d, the loader caught it, logged one line, and reported an empty
 * route table — so the plugin's entire admin surface (the dashboard screen
 * included) never registered on a real site, while the suite stayed green,
 * because `WordPressRouterAdapterTest` drives the adapter directly and
 * `RouteListCommandTest`'s fixture files are typed against the base registrar.
 *
 * ## What is asserted, and why not the routes themselves
 *
 * The floor is that loading *the plugin's own* routes file reports no failure —
 * that single assertion is the one that was false, and it is what these tests are
 * for. Beyond it, the surfaces are asserted: the slugs from the controller's own
 * constants, so renaming a screen does not fail a test about loading one.
 */
final class WebRoutesFileTest extends TestCase
{
    private Container $container;
    private WordPressRouterAdapter $router;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->container = new Container();
        $this->container->instance(ContainerInterface::class, $this->container);

        // The HTTP layer, so the middleware classes the routes file names are
        // resolvable — the same provider a real project registers.
        (new HttpServiceProvider($this->container))->register();

        $this->router = new WordPressRouterAdapter(
            $this->container,
            new RouteResolver($this->container),
            new CaptureWebRequestFactory()
        );
    }

    // ------------------------------------------------------------ the seam

    /**
     * The registrar a web routes file is handed can register all three surfaces.
     *
     * Asserted on the type rather than by calling the methods, because the
     * methods are already covered by the adapter tests — what was wrong was which
     * object the loader produces, and that is a fact about the type.
     */
    public function test_the_web_router_hands_out_a_registrar_with_the_surface_helpers(): void
    {
        $registrar = $this->router->registrar();

        $this->assertInstanceOf(WebRouteRegistrar::class, $registrar);
        $this->assertTrue(
            method_exists($registrar, 'adminPage')
            && method_exists($registrar, 'adminAction')
            && method_exists($registrar, 'page'),
            'A web routes file receives this object, so it has to carry the surfaces.'
        );

        // Still a registrar, which is what the contract promises every caller and
        // what keeps `group()` and the verb methods available in `web.php`.
        $this->assertInstanceOf(RouteRegistrar::class, $registrar);
        $this->assertTrue(method_exists($registrar, 'group'));
    }

    /**
     * A routes file written against the documented type loads.
     */
    public function test_a_web_routes_file_is_loaded_through_the_registrar(): void
    {
        $loader = $this->loaderWith([
            'routes/web.php' => <<<'PHP'
                <?php
                return function (\Framework\Routing\WebRouteRegistrar $router): void {
                    $router->adminPage('leads', \Tests\Unit\Routing\Fixtures\StringController::class, 'index');
                    $router->adminAction('leads/store', \Tests\Unit\Routing\Fixtures\StringController::class, 'store');
                    $router->page('thank-you', \Tests\Unit\Routing\Fixtures\StringController::class, 'index');
                };
                PHP,
        ]);

        $result = $loader->load($this->router, 'routes/web.php');

        $this->assertFalse(
            $result->failed(),
            'The loader could not hand the file a usable router: ' . $result->describe()
        );
        $this->assertSame(3, $result->routeCount);
    }

    // -------------------------------------------------- the shipped file

    /**
     * **The regression.** The plugin's own routes file must load.
     *
     * A failure here is not a routing bug in the abstract: it means every screen
     * and handler declared in that file is absent from the running site, with one
     * error-log line as the only symptom.
     */
    public function test_the_plugin_ships_a_web_routes_file_that_loads(): void
    {
        $result = $this->loader()->load($this->router, 'routes/web.php');

        $this->assertFalse($result->failed(), $result->describe());
        $this->assertGreaterThan(0, $result->routeCount);

        $paths = array_map(
            static fn ($route): string => $route->getPath(),
            $this->router->getRoutes()
        );

        $this->assertContains('wp-mini-laravel', $paths, 'The existing dashboard screen went missing.');
        $this->assertContains(LeadController::SCREEN_SLUG, $paths);
        $this->assertContains(LeadController::CREATE_SLUG, $paths);
    }

    /**
     * The loaded file publishes the menus WordPress needs, parent before submenu.
     *
     * The ordering is the reason this is asserted here rather than left to the
     * adapter tests: it is a property of the *file's* declaration order, and
     * `add_submenu_page()` back-fills the parent's own submenu entry only if the
     * parent is already registered. Reversed, the top-level menu is labelled with
     * its first submenu item's title and the list screen becomes unreachable.
     *
     * `flush()` only hooks `admin_menu`; the menu functions run when WordPress
     * fires it, so the action is fired here before anything is read back.
     */
    public function test_the_loaded_file_publishes_the_leads_menu_before_its_submenu(): void
    {
        $this->loader()->load($this->router, 'routes/web.php');
        $this->router->flush();
        WpStub::doAction('admin_menu');

        $slugs = array_column(WpStub::$menuPages, 'menu_slug');

        $this->assertSame(
            ['wp-mini-laravel', LeadController::SCREEN_SLUG],
            $slugs,
            'Top-level menus, in registration order.'
        );

        $submenuParents = array_column(WpStub::$submenuPages, 'parent_slug');

        $this->assertSame(
            [LeadController::SCREEN_SLUG],
            $submenuParents,
            'The create screen is a submenu of the list screen.'
        );
        $this->assertSame(
            [LeadController::CREATE_SLUG],
            array_column(WpStub::$submenuPages, 'menu_slug')
        );
    }

    /**
     * The form handler publishes on the action name the form posts to.
     *
     * Derived at flush time from the path, so this is the one assertion that ties
     * the route declaration to the WordPress hook WordPress will actually fire.
     */
    public function test_the_loaded_file_publishes_the_form_handler(): void
    {
        $this->loader()->load($this->router, 'routes/web.php');
        $this->router->flush();

        $this->assertArrayHasKey(
            'admin_post_' . LeadController::STORE_ACTION,
            WpStub::$actions,
            'The form posts this action name; WordPress must have a handler for it.'
        );
    }

    // ------------------------------------------------ the shipped middleware

    /**
     * The store route carries the parameterized guards the framework ships.
     *
     * **The refactor's own regression test.** The route used to name two
     * domain-specific classes — `RequireLeadAccess`, `VerifyLeadForm` — that
     * wrapped a capability and a nonce action in a constructor. Those are gone,
     * and the configuration now lives in the routes file as `'capability:...'`
     * and `'nonce:...'`. If that edit were ever reverted, or the parameters
     * dropped in a refactor, the route would still register and still dispatch —
     * it would simply run with **no capability check and no nonce check**, which
     * is the failure this asserts against.
     *
     * Asserted on the raw entries rather than resolved classes, because the raw
     * entry is what the adapter is handed and what the pipeline splits; a test
     * that resolved them first would not notice the parameters going missing.
     */
    public function test_the_store_route_declares_the_parameterized_guards(): void
    {
        $this->loader()->load($this->router, 'routes/web.php');

        $store = $this->routeFor('leads/store');

        $this->assertNotNull($store, 'The store route was not declared.');

        $this->assertSame(
            [
                Authenticate::class,
                'capability:' . LeadController::CAPABILITY,
                'nonce:' . LeadController::STORE_ACTION,
                TrimStrings::class,
                ConvertEmptyStringsToNull::class,
                SanitizeInput::class,
            ],
            $store->getMiddleware()
        );
    }

    /**
     * The capability and the nonce action are read from the controller's own
     * constants, so the guard and the form that mints the nonce cannot disagree.
     *
     * A literal here — `'capability:list_users'` — would be a second spelling of
     * a policy that already has one, and changing the constant would leave the
     * route guarding the old capability with nothing to report it.
     */
    public function test_the_store_route_reads_its_policy_from_the_controller(): void
    {
        $this->loader()->load($this->router, 'routes/web.php');

        $middleware = $this->routeFor('leads/store')?->getMiddleware() ?? [];

        $this->assertContains('capability:' . LeadController::CAPABILITY, $middleware);
        $this->assertContains('nonce:' . LeadController::STORE_ACTION, $middleware);
    }

    /**
     * The two guards the framework no longer ships as domain classes must not be
     * referenced — a route naming a deleted class registers cleanly and fails at
     * dispatch, which is a 500 on a live form rather than anything a reader sees.
     */
    public function test_the_store_route_names_no_deleted_domain_middleware(): void
    {
        $this->loader()->load($this->router, 'routes/web.php');

        $middleware = $this->routeFor('leads/store')?->getMiddleware() ?? [];

        foreach ($middleware as $entry) {
            $class = str_contains($entry, ':') ? explode(':', $entry, 2)[0] : $entry;

            $this->assertNotSame('App\Http\Middleware\RequireLeadAccess', $class);
            $this->assertNotSame('App\Http\Middleware\VerifyLeadForm', $class);
        }
    }

    // --------------------------------------------------------- group middleware

    /**
     * A surface helper declared inside a `group()` still gets the group's middleware.
     *
     * The helpers build their route on the adapter rather than through the
     * registrar's own `add()`, so this is the case where the group accumulator
     * could be bypassed — accepting `group(['middleware' => [...]], ...)` around
     * an admin screen and then silently running nothing. Dropping a guard the
     * author declared is a security bug, so it is pinned rather than assumed.
     */
    public function test_group_middleware_reaches_a_route_declared_with_a_surface_helper(): void
    {
        $loader = $this->loaderWith([
            'routes/web.php' => <<<'PHP'
                <?php
                return function (\Framework\Routing\WebRouteRegistrar $router): void {
                    $router->group(['middleware' => [\Framework\Http\Middleware\Authenticate::class]], function ($router): void {
                        $router->adminPage('secure', \Tests\Unit\Routing\Fixtures\StringController::class, 'index');
                    });
                };
                PHP,
        ]);

        $loader->load($this->router, 'routes/web.php');

        $routes = $this->router->getRoutes();

        $this->assertCount(1, $routes);
        $this->assertSame([Authenticate::class], $routes[0]->getMiddleware());
    }

    /**
     * ...and does not leak into a route declared outside the group.
     */
    public function test_group_middleware_does_not_leak_past_the_group(): void
    {
        $loader = $this->loaderWith([
            'routes/web.php' => <<<'PHP'
                <?php
                return function (\Framework\Routing\WebRouteRegistrar $router): void {
                    $router->group(['middleware' => [\Framework\Http\Middleware\Authenticate::class]], function ($router): void {
                        $router->adminPage('secure', \Tests\Unit\Routing\Fixtures\StringController::class, 'index');
                    });

                    $router->adminPage('open', \Tests\Unit\Routing\Fixtures\StringController::class, 'index');
                };
                PHP,
        ]);

        $loader->load($this->router, 'routes/web.php');

        $routes = $this->router->getRoutes();

        $this->assertSame([Authenticate::class], $routes[0]->getMiddleware());
        $this->assertSame([], $routes[1]->getMiddleware());
    }

    // ------------------------------------------------------- domain isolation

    /**
     * **The two domains do not bleed into each other.**
     *
     * `routes/web.php` and `routes/api.php` hold the *same* facade call —
     * `Route::get('leads', [...])` appears in both, meaning different things. Which
     * table it lands in is decided entirely by the target {@see RouteLoader} aims
     * the facade at while the file runs, and that target is static state on a
     * facade: aim it at the wrong router and a web route becomes a REST endpoint,
     * with no error anywhere.
     *
     * Two files on disk rather than the plugin's real pair, so the assertion is
     * about the seam and not about what this project happens to route today.
     *
     * ## Two tests, because one line of code produces two different bugs
     *
     * The target has to be *set* before the file runs and *replaced* for the next
     * file. Those are separate statements, and each fails on its own:
     *
     *  - Drop the set — the facade still holds an earlier target, so the file
     *    writes into whichever router ran last. Covered by the **paired** version
     *    below, where a web load precedes the API one.
     *  - Drop the replace — with a *single* load the facade is empty on entry and
     *    the file registers correctly, so a one-load test passes while every
     *    multi-domain load is broken.
     *
     * Hence both shapes are asserted. The single-load one pins the set; the paired
     * one pins the replace, and it is the one that would have caught a facade that
     * only worked for the first file of a request.
     */
    public function test_a_single_load_lands_in_the_router_it_was_aimed_at(): void
    {
        $web = $this->loaderWith([
            'routes/web.php' => $this->facadeRoutes("Route::get('from-web', [\\stdClass::class, 'index']);"),
        ]);

        $api = $this->apiRouter();
        $apiLoader = $this->loaderWith([
            'routes/api.php' => $this->facadeRoutes("Route::get('from-api', [\\stdClass::class, 'index']);"),
        ]);

        $web->load($this->router, 'routes/web.php');
        $apiLoader->load($api, 'routes/api.php');

        $this->assertSame(['from-web'], $this->pathsOf($this->router));
        $this->assertSame(['from-api'], $this->pathsOf($api));
    }

    /**
     * **The replace, which is where the contamination actually happens.**
     *
     * Same loader, one load straight after the other — the shape a real request
     * takes when `admin_menu` fires and then a REST route is resolved, and the
     * exact shape `route:list` takes when it fills both tables.
     *
     * Because the facade's target is static, the second load only lands correctly
     * if the first one left it aimed somewhere it could be swapped *away* from.
     * Without that, the API file's `Route::get('from-api', ...)` registers into the
     * web table and the REST API 404s on everything — silently, since both calls
     * are valid and neither raises.
     */
    public function test_the_second_load_re_aims_the_facade_rather_than_reusing_the_first(): void
    {
        $api = $this->apiRouter();

        $loader = $this->loaderWith([
            'routes/web.php' => $this->facadeRoutes("Route::get('from-web', [\\stdClass::class, 'index']);"),
            'routes/api.php' => $this->facadeRoutes("Route::get('from-api', [\\stdClass::class, 'index']);"),
        ]);

        $loader->load($this->router, 'routes/web.php');
        $loader->load($api, 'routes/api.php');

        $this->assertSame(
            ['from-web'],
            $this->pathsOf($this->router),
            'The API file registered into the web router: the facade was never re-aimed.'
        );
        $this->assertSame(
            ['from-api'],
            $this->pathsOf($api),
            'The API router is empty, so the second load went into the wrong table.'
        );
    }

    /**
     * The reverse order, so the contamination is not an artefact of one direction.
     *
     * `route:list` reads the API file first; the web hooks can fire in any order.
     * A facade that only works one way round is one that will fail in production
     * for whichever caller happened to go second.
     */
    public function test_the_second_load_re_aims_the_facade_in_either_order(): void
    {
        $api = $this->apiRouter();

        $loader = $this->loaderWith([
            'routes/web.php' => $this->facadeRoutes("Route::get('from-web', [\\stdClass::class, 'index']);"),
            'routes/api.php' => $this->facadeRoutes("Route::get('from-api', [\\stdClass::class, 'index']);"),
        ]);

        $loader->load($api, 'routes/api.php');
        $loader->load($this->router, 'routes/web.php');

        $this->assertSame(['from-api'], $this->pathsOf($api));
        $this->assertSame(
            ['from-web'],
            $this->pathsOf($this->router),
            'The web file registered into whichever router the API load left behind.'
        );
    }

    /**
     * One loader can fill both tables.
     *
     * This is what `route:list` does in a single process, and it is why the
     * loaded-file record is keyed by path *and* router: keyed by path alone, the
     * second domain's load would be skipped as "already loaded" and that table
     * would render empty — which reads as "this domain has no routes", not as a
     * bug in the bookkeeping.
     */
    public function test_one_loader_can_fill_both_routers(): void
    {
        $api = $this->apiRouter();

        $loader = $this->loaderWith([
            'routes/web.php' => $this->facadeRoutes("Route::get('from-web', [\\stdClass::class, 'index']);"),
            'routes/api.php' => $this->facadeRoutes("Route::get('from-api', [\\stdClass::class, 'index']);"),
        ]);

        $this->assertFalse($loader->load($this->router, 'routes/web.php')->failed());
        $this->assertFalse($loader->load($api, 'routes/api.php')->failed());

        $this->assertCount(1, $this->pathsOf($this->router));
        $this->assertCount(1, $this->pathsOf($api));
    }

    // ----------------------------------------------------------------- helpers

    /**
     * A facade-style routes file wrapping the given body.
     *
     * The `use` line is load-bearing rather than cosmetic: it is the exact
     * statement that pulls in the facade whose target exists only while the loader
     * is running the file. The body must therefore reference `Route` unqualified —
     * a leading `\` would name a global class that does not exist and defeat the
     * import this fixture exists to exercise.
     */
    private function facadeRoutes(string $body): string
    {
        return "<?php\nuse Framework\\Support\\Facades\\Route;\n\n" . $body . "\n";
    }

    /**
     * The API half, wired the same way `RouteServiceProvider` wires it.
     */
    private function apiRouter(): RestApiRouterAdapter
    {
        return new RestApiRouterAdapter(
            $this->container,
            new RouteResolver($this->container)
        );
    }

    /**
     * @return list<string>
     */
    private function pathsOf(\Framework\Routing\Contracts\RouterInterface $router): array
    {
        $paths = [];

        foreach ($router->getRoutes() as $route) {
            $paths[] = $route->getPath();
        }

        return $paths;
    }

    /**
     * One registered web route by path, or null if the file declared none.
     *
     * Returned rather than asserted so a caller can name its own failure — and so
     * the middleware assertions read as being about the route rather than about
     * an index into `getRoutes()`, which shifts the moment the file gains a screen.
     */
    private function routeFor(string $path): ?\Framework\Routing\Route
    {
        foreach ($this->router->getRoutes() as $route) {
            if ($route->getPath() === $path) {
                return $route;
            }
        }

        return null;
    }

    /**
     * A loader rooted at the plugin, so `routes/web.php` is the real one.
     *
     * Three levels up from `tests/Integration/Routing`, which is where the plugin
     * root sits relative to this file.
     */
    private function loader(): RouteLoader
    {
        return new RouteLoader(dirname(__DIR__, 3));
    }

    /**
     * A loader whose base path contains the given files.
     *
     * The paths are relative to the base path and mirror the real layout, because
     * the loader is asked for `routes/web.php` and would otherwise be reading a
     * file the test never wrote.
     *
     * @param array<string, string> $files relative path => contents
     */
    private function loaderWith(array $files): RouteLoader
    {
        $dir = sys_get_temp_dir() . '/wp-mini-laravel-web-' . bin2hex(random_bytes(4));

        foreach ($files as $name => $contents) {
            $path = $dir . '/' . $name;
            $parent = dirname($path);

            if (!is_dir($parent)) {
                mkdir($parent, 0777, true);
            }

            file_put_contents($path, $contents);
        }

        return new RouteLoader($dir);
    }
}
