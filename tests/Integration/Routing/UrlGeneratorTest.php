<?php
declare(strict_types=1);

namespace Tests\Integration\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use Framework\Routing\Adapters\RestApiRouterAdapter;
use Framework\Routing\Adapters\WordPressRouterAdapter;
use Framework\Routing\CaptureWebRequestFactory;
use Framework\Routing\NamedRoutes;
use Framework\Routing\Route;
use Framework\Routing\RouteResolver;
use Framework\Routing\Router;
use Framework\Routing\UrlGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\WpStub;

/**
 * `route()` produces the URL WordPress actually serves a route at.
 *
 * ## What these assertions are actually pinning
 *
 * The URL *functions* are WordPress's — `admin_url()`, `rest_url()`, `home_url()`
 * — and the generator delegates to them rather than computing a host, because the
 * scheme, the host, the installation subdirectory and whether the site is behind a
 * proxy are all things WordPress knows and a plugin does not.
 *
 * So the assertions split into the two halves of that division:
 *
 *  - **The path the generator hands over** — `admin.php?page=leads`,
 *    `wp-mini-laravel/v1/leads/42` — which is the part this framework owns, and
 *    which is asserted against the stub's own prefix so a change to either side is
 *    visible.
 *  - **That the prefix comes from WordPress** — asserted explicitly at the bottom,
 *    including that a front-end page takes `home_url()`'s base rather than the
 *    admin's.
 *
 * The stubs define `admin_url()` and `home_url()` but deliberately not
 * `rest_url()`, so the REST tests read a bare path and the admin and front-end
 * ones read a prefixed one. Both are correct; the difference is the stub, not the
 * generator.
 *
 * ## What is not covered by construction
 *
 * Every route here is declared in the test rather than read from
 * `routes/api.php`, so the suite does not fail when the project adds a route.
 */
final class UrlGeneratorTest extends TestCase
{
    /** The prefix the WordPress stubs answer `admin_url()` with. */
    private const ADMIN_BASE = 'http://example.test/wp-admin/';

    /** The prefix the WordPress stubs answer `home_url()` with. */
    private const HOME_BASE = 'http://example.test';
    private Container $container;
    private NamedRoutes $named;
    private UrlGenerator $generator;
    private WordPressRouterAdapter $web;
    private RestApiRouterAdapter $api;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->container = new Container();
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->named = new NamedRoutes();
        $this->generator = new UrlGenerator($this->named, 'wp-mini-laravel/v1');

        // Built once and held, because a registrar writes into *its own* adapter's
        // table: a helper returning a fresh instance per call would register routes
        // on one object and flush an empty other.
        $this->web = new WordPressRouterAdapter(
            $this->container,
            new RouteResolver($this->container),
            new CaptureWebRequestFactory(),
            null,
            static function (): void {},
            $this->named
        );

        $this->api = new RestApiRouterAdapter(
            $this->container,
            new RouteResolver($this->container),
            $this->named
        );
    }

    // ----------------------------------------------------------- admin screens

    /**
     * An admin screen is addressed by a query parameter, not a path.
     *
     * `admin.php?page={slug}`, and the slug is the route's path verbatim — that is
     * what `add_menu_page()` was handed. A generator that "cleaned up" the slug
     * would produce a link to a screen that does not exist, so the assertion is
     * exact rather than a prefix match.
     */
    public function test_an_admin_screen_generates_an_admin_php_page_url(): void
    {
        $this->web()->registrar()
            ->get('leads', [\stdClass::class, 'index'])
            ->name('leads.index');

        $this->web()->flush();

        $this->assertSame(
            self::ADMIN_BASE . 'admin.php?page=leads',
            $this->generator->route('leads.index')
        );
    }

    /**
     * A slug containing a slash survives, because a sub-page screen keeps its full
     * path as the slug.
     */
    public function test_an_admin_screen_slug_with_separators_is_preserved(): void
    {
        $this->web()->registrar()
            ->get('leads-reports-summary', [\stdClass::class, 'index'])
            ->name('leads.reports');

        $this->web()->flush();

        $this->assertSame(
            self::ADMIN_BASE . 'admin.php?page=leads-reports-summary',
            $this->generator->route('leads.reports')
        );
    }

    // ---------------------------------------------------------- form handlers

    /**
     * A form handler is `admin-post.php?action={action}` — a *different file* and a
     * different parameter from an admin screen, which is the mistake this test
     * exists to catch. Both are `admin_url()`, so a generator that confused them
     * would still produce a plausible-looking URL that posts to nothing.
     */
    public function test_a_form_handler_generates_an_admin_post_action_url(): void
    {
        $this->web()->registrar()
            ->post('leads/store', [\stdClass::class, 'store'])
            ->name('leads.store');

        $this->web()->flush();

        $this->assertSame(
            self::ADMIN_BASE . 'admin-post.php?action=leads_store',
            $this->generator->route('leads.store')
        );
    }

    /**
     * **The action name is the one WordPress was hooked on, not a fresh guess.**
     *
     * The adapter resolves the `admin_post_{action}` name at flush time and records
     * it on the route. The generator reads that recorded value.
     *
     * ## Why this uses an explicit `action` option
     *
     * It is the only case that *discriminates*. The adapter's fallback derivation
     * is `preg_replace('/[^A-Za-z0-9]+/', '_', $name)` — so for a route named
     * `leads.store` the derived name is `leads_store`, and a generator that
     * re-derived instead of reading would produce the identical string and pass by
     * coincidence.
     *
     * An explicit `action` is not derivable from anything: the adapter honours it
     * verbatim, so only a generator that reads the recorded value can produce it.
     * That is also the case a project actually uses — `LeadsController::STORE_ACTION`
     * in this repo is exactly this, a hand-picked action name so the URL does not
     * change when the route is renamed.
     */
    public function test_the_action_name_is_read_from_the_route_not_re_derived(): void
    {
        $this->web()->registrar()
            ->post('some/path/that/differs', [\stdClass::class, 'store'])
            ->name('distinct.route.name')
            ->withOptions(['action' => 'hand-picked-action']);

        $this->web()->flush();

        // The fallback derivation would have produced `distinct_route_name`, so the
        // assertion fails for a generator that re-derives.
        $this->assertSame(
            self::ADMIN_BASE . 'admin-post.php?action=hand-picked-action',
            $this->generator->route('distinct.route.name')
        );
    }

    /**
     * And a non-POST verb keeps the suffix the adapter appends.
     *
     * `PUT leads/{id}` and `DELETE leads/{id}` would otherwise derive one name and
     * share a hook. The suffix is the adapter's rule, and this asserts the
     * generator inherits it rather than inventing a second one — a re-derivation
     * here would produce a URL posting to a hook nobody registered.
     */
    public function test_a_non_post_verb_keeps_the_derived_action_suffix(): void
    {
        $this->web()->registrar()
            ->put('leads/update', [\stdClass::class, 'update'])
            ->name('leads.update');

        $this->web()->flush();

        $this->assertSame(
            self::ADMIN_BASE . 'admin-post.php?action=leads_update_put',
            $this->generator->route('leads.update')
        );
    }

    // ------------------------------------------------------------- REST routes

    /**
     * A REST endpoint is a real path under the namespace, not a query parameter.
     */
    public function test_a_rest_endpoint_generates_a_namespaced_rest_url(): void
    {
        $this->api()->registrar()
            ->get('leads', [\stdClass::class, 'index'])
            ->name('api.leads.index');

        $this->api()->flush();

        $this->assertSame(
            'wp-mini-laravel/v1/leads',
            $this->generator->route('api.leads.index')
        );
    }

    /**
     * **A placeholder is replaced by the value passed in.**
     *
     * The case the whole `$parameters` argument exists for. `leads/{id}` must not
     * generate with the literal `{id}` in it, which is a URL WordPress would 404
     * with a valid-looking shape.
     */
    public function test_a_rest_placeholder_is_substituted_with_its_value(): void
    {
        $this->api()->registrar()
            ->get('leads/{id}', [\stdClass::class, 'show'])
            ->name('api.leads.show');

        $this->api()->flush();

        $this->assertSame(
            'wp-mini-laravel/v1/leads/42',
            $this->generator->route('api.leads.show', ['id' => 42])
        );
    }

    /**
     * Several placeholders, replaced independently.
     */
    public function test_multiple_placeholders_are_all_substituted(): void
    {
        $this->api()->registrar()
            ->get('leads/{lead}/notes/{note}', [\stdClass::class, 'show'])
            ->name('api.leads.notes.show');

        $this->api()->flush();

        $this->assertSame(
            'wp-mini-laravel/v1/leads/7/notes/3',
            $this->generator->route('api.leads.notes.show', ['lead' => 7, 'note' => 3])
        );
    }

    /**
     * A value is URL-encoded, so a name with a space or a slash in it produces a
     * usable URL rather than a broken one.
     */
    public function test_a_parameter_value_is_url_encoded(): void
    {
        $this->api()->registrar()
            ->get('leads/{slug}', [\stdClass::class, 'show'])
            ->name('api.leads.slug');

        $this->api()->flush();

        $this->assertSame(
            'wp-mini-laravel/v1/leads/acme%20corp',
            $this->generator->route('api.leads.slug', ['slug' => 'acme corp'])
        );
    }

    /**
     * **A missing placeholder is an error, not an empty segment.**
     *
     * Silently dropping it would produce `/leads/` — a valid URL, resolving to
     * nothing, in a template that looks correct. This is the failure mode worth an
     * exception: nothing downstream can detect it.
     */
    public function test_a_missing_parameter_is_refused_rather_than_dropped(): void
    {
        $this->api()->registrar()
            ->get('leads/{id}', [\stdClass::class, 'show'])
            ->name('api.leads.show');

        $this->api()->flush();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/needs a value for parameter \[\{id\}\]/');

        $this->generator->route('api.leads.show');
    }

    /**
     * A non-scalar value is refused rather than cast. `(string) []` is "Array",
     * which would generate a URL that silently goes nowhere.
     */
    public function test_a_non_scalar_parameter_is_refused(): void
    {
        $this->api()->registrar()
            ->get('leads/{id}', [\stdClass::class, 'show'])
            ->name('api.leads.show');

        $this->api()->flush();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/must be a scalar/');

        /** @phpstan-ignore-next-line deliberately the wrong type */
        $this->generator->route('api.leads.show', ['id' => ['nested']]);
    }

    /**
     * A path that has already been compiled to WordPress's regex form still
     * generates a clean URL.
     *
     * `Route::getPath()` normally holds `{id}`, but a route that came through
     * {@see \Framework\Routing\RouteCompiler} holds `(?P<id>[^/]+)`. Handling only
     * the first spelling would put a regex into a URL, so both are exercised.
     */
    public function test_a_compiled_regex_placeholder_is_also_substituted(): void
    {
        $route = Route::make(['GET'], 'leads/(?P<id>[^/]+)')->name('compiled')->uses(\stdClass::class, 'show');

        $this->named->add($this->routerWith($route));

        $this->assertSame(
            'wp-mini-laravel/v1/leads/9',
            $this->generator->route('compiled', ['id' => 9])
        );
    }

    // ------------------------------------------------------------ front-end

    /**
     * **A front-end page is a real path on the site, so it is `home_url()`'s.**
     *
     * ## The bug this pins
     *
     * A `page()` route carries `SURFACE_PAGE`, and `compile()`'s match had no arm
     * for it, so it fell through to `default` — the REST branch. The generated URL
     * was `/wp-json/{namespace}/thank-you`: plausible-looking, and wrong in every
     * part, because nothing serves a front-end page under the REST namespace. The
     * failure mode is the one worth a test rather than a comment: a link that 404s
     * silently, in a template that had every reason to believe it was correct.
     *
     * So this asserts through `route()` and not through
     * {@see UrlGenerator::page()}, because the lookup is what was broken. The
     * explicit `page()` spelling calls {@see UrlGenerator::compileFrontEndPage()}
     * directly and would have passed the whole time.
     *
     * The route goes in through the registrar rather than by hand, so the surface
     * it asserts on is the one a real `page()` declaration produces — a route
     * assembled with the option set manually could agree with the generator while
     * disagreeing with the registrar.
     */
    public function test_a_named_front_end_page_generates_a_home_url(): void
    {
        $this->web()->registrar()
            ->page('thank-you', [\stdClass::class, 'index'])
            ->name('thanks');

        $this->web()->flush();

        // `home_url()`'s base, not `admin_url()`'s and not the REST namespace: a
        // front-end page lives on the site, and either other prefix would produce a
        // URL under a directory that serves nothing.
        $this->assertSame(self::HOME_BASE . '/thank-you', $this->generator->route('thanks'));
    }

    /**
     * A `page()` route reached by name is never compiled as a REST endpoint.
     *
     * The regression stated negatively, against the exact string the bug produced.
     * `test_a_named_front_end_page_generates_a_home_url()` above would also pass if
     * the namespace were empty, or if a site ever served `/wp-json/...` — so the
     * namespace being present is asserted separately, and absent from this URL.
     */
    public function test_a_named_front_end_page_is_not_compiled_as_a_rest_endpoint(): void
    {
        $this->web()->registrar()
            ->page('thank-you', [\stdClass::class, 'index'])
            ->name('thanks');

        $this->web()->flush();

        $url = $this->generator->route('thanks');

        $this->assertStringNotContainsString('wp-json', $url);
        $this->assertStringNotContainsString('wp-mini-laravel/v1', $url);
    }

    /**
     * **A front-end page with a placeholder puts it in the path.**
     *
     * `thank-you/{order}` is a path with a segment in it — WordPress matches it as
     * one — so the value belongs in the path and not in a query string. Asserting
     * the whole URL, because a generator that moved it to `?order=7` would still
     * contain the value and still look right at a glance.
     */
    public function test_a_front_end_page_substitutes_a_placeholder_in_the_path(): void
    {
        $this->web()->registrar()
            ->page('thank-you/{order}', [\stdClass::class, 'index'])
            ->name('thanks.order');

        $this->web()->flush();

        $this->assertSame(
            self::HOME_BASE . '/thank-you/1024',
            $this->generator->route('thanks.order', ['order' => 1024])
        );
    }

    /**
     * **A parameter the path did not consume becomes a query string.**
     *
     * The other half of "appending parameters appropriately": a page route has a
     * fixed path, so `route('leads', ['status' => 'new'])` has nowhere to put
     * `status` in it. Dropping it would send the user to the *unfiltered* page,
     * which reads as the filter being broken rather than the link.
     *
     * Both are asserted together — the path is unchanged and the extra parameter is
     * in the query — because either alone is satisfied by a generator that ignores
     * one of the two.
     */
    public function test_a_parameter_the_path_did_not_consume_is_appended_as_a_query_string(): void
    {
        $this->web()->registrar()
            ->page('leads', [\stdClass::class, 'index'])
            ->name('leads.page');

        $this->web()->flush();

        $this->assertSame(
            self::HOME_BASE . '/leads?status=new&page=2',
            $this->generator->route('leads.page', ['status' => 'new', 'page' => 2])
        );
    }

    /**
     * Query values are `rawurlencode`d, matching the path segments.
     *
     * One encoding rule for the whole generator: `http_build_query()` would have
     * turned this space into `+`, which is wrong outside a form body and would
     * disagree with what the path substitution does with the same value.
     */
    public function test_a_query_string_value_is_url_encoded(): void
    {
        $this->web()->registrar()
            ->page('leads', [\stdClass::class, 'index'])
            ->name('leads.page');

        $this->web()->flush();

        $this->assertSame(
            self::HOME_BASE . '/leads?q=hello%20world',
            $this->generator->route('leads.page', ['q' => 'hello world'])
        );
    }

    /**
     * A `page()` route held directly still compiles to a `home_url()`.
     *
     * The explicit spelling, kept because it is the only way to link an *unnamed*
     * route: a template part handed a `Route` has no name to look up. It shares
     * {@see UrlGenerator::compileFrontEndPage()} with the lookup path above, so the
     * two cannot disagree about what a front-end page compiles to.
     */
    public function test_a_front_end_page_generates_a_home_url_path(): void
    {
        $route = Route::make(['GET'], 'thank-you')->name('thanks')->uses(\stdClass::class, 'index');
        $route->option(WordPressRouterAdapter::OPTION_SURFACE, WordPressRouterAdapter::SURFACE_PAGE);

        // Prefixed by `home_url()`, not by `admin_url()` — a front-end page lives on
        // the site, and using the admin's base would produce a URL under /wp-admin
        // that no theme ever serves.
        $this->assertSame(self::HOME_BASE . '/thank-you', $this->generator->page($route));
    }

    /**
     * **A surface-less route is REST, because nothing else is left.**
     *
     * The rule the failing alternative would break. A tempting refinement is to
     * notice that a route with no `surface` option never passed through a web
     * registrar and ask the web adapter to infer one from the verb. But a *REST*
     * route also never passed through one — `Route::get('leads', ...)` carries no
     * surface either — and the inference would classify it as a front-end page and
     * generate `home_url('leads')`: a URL on the site, where no page exists, in
     * place of the endpoint that was just registered.
     *
     * So the negative half is asserted as well as the positive: the generated URL
     * is the namespaced REST path and *not* prefixed by `home_url()`'s base.
     */
    public function test_a_surface_less_get_route_is_rest_and_not_a_front_end_page(): void
    {
        $route = Route::make(['GET'], 'leads')->name('surfaceless')->uses(\stdClass::class, 'index');

        $this->named->add($this->routerWith($route));

        $url = $this->generator->route('surfaceless');

        $this->assertSame('wp-mini-laravel/v1/leads', $url);
        $this->assertStringNotContainsString(self::HOME_BASE, $url);
    }

    /**
     * An explicit surface option is what selects a non-REST mechanism, and it wins.
     *
     * The other direction of the rule above: a hand-built `Route` *can* be an admin
     * screen or a front-end page, but only by saying so. Asserted for the admin
     * screen because it is the case the adapter's own inference would get wrong —
     * a GET route is a `TEMPLATE_REDIRECT` page there and an admin screen here, and
     * this is what a route registered through the registrar actually carries.
     */
    public function test_an_explicit_surface_option_selects_the_mechanism(): void
    {
        $route = Route::make(['GET'], 'leads')->name('explicit.screen')->uses(\stdClass::class, 'index');
        $route->option(WordPressRouterAdapter::OPTION_SURFACE, WordPressRouterAdapter::SURFACE_ADMIN_PAGE);

        $this->named->add($this->routerWith($route));

        $this->assertSame(self::ADMIN_BASE . 'admin.php?page=leads', $this->generator->route('explicit.screen'));
    }

    /**
     * **Without `rest_url()`, the bare namespaced path is what comes back.**
     *
     * The stubs define `admin_url()` and `home_url()` but not `rest_url()`, so the
     * REST branch is the one that exercises the generator's no-WordPress fallback.
     * That fallback is not a gap in the test — it is the behaviour `route()` has to
     * have under WP-CLI and in a unit test, where none of the URL helpers are
     * loaded, and it is the behaviour the REST assertions above rely on.
     *
     * ## Why the prefix cannot simply be stubbed here
     *
     * `UrlGenerator` calls `rest_url()` unqualified, so it resolves in
     * `Framework\Routing` first and then falls through to the global one. A test
     * declaring it in that namespace would work — that is how `builtins.php` stubs
     * `setcookie()` for `RedirectResponse` — but it is process-global and
     * unremovable, so the *other* REST tests in this file would then pick up the
     * prefix depending on execution order. That is the kind of order-dependence
     * that makes a suite lie, so this test asserts the fallback instead and leaves
     * the namespaced stub for the one builtin that needs it.
     *
     * The prefix delegation itself is covered by the admin and front-end branches
     * below, which go through the same `url()` method with the same
     * `function_exists()` guard.
     */
    public function test_a_rest_endpoint_falls_back_to_the_bare_path_without_wordpress(): void
    {
        $this->api()->registrar()
            ->get('leads/{id}', [\stdClass::class, 'show'])
            ->name('api.leads.show');

        $this->api()->flush();

        $this->assertSame(
            'wp-mini-laravel/v1/leads/42',
            $this->generator->route('api.leads.show', ['id' => 42])
        );
    }

    // ------------------------------------------------------------- WordPress

    /**
     * **When WordPress is loaded, its URL functions supply the prefix.**
     *
     * The half of the job this framework must *not* do. `admin_url()`, `rest_url()`
     * and `home_url()` know the scheme, the host, the installation subdirectory and
     * whether the site is behind a proxy; a plugin does not. So the assertion is
     * that the generator delegates rather than computes.
     */
    public function test_the_wordpress_url_functions_supply_the_prefix_when_loaded(): void
    {
        WpStub::$adminUrl = 'https://site.test/wp-admin/';
        WpStub::$homeUrl = 'https://site.test/';

        $this->web()->registrar()->get('leads', [\stdClass::class, 'index'])->name('leads.index');
        $this->web()->registrar()->post('leads/store', [\stdClass::class, 'store'])->name('leads.store');
        $this->web()->flush();

        $this->assertSame(
            'https://site.test/wp-admin/admin.php?page=leads',
            $this->generator->route('leads.index')
        );
        $this->assertSame(
            'https://site.test/wp-admin/admin-post.php?action=leads_store',
            $this->generator->route('leads.store')
        );
    }

    // --------------------------------------------------------------- failures

    /**
     * An unknown name is reported with the names that *do* exist.
     *
     * A typo is the usual cause, and the list is what turns a hunt through the
     * routes files into a glance — so the assertion is on the list, not just on the
     * exception type.
     */
    public function test_an_unknown_name_is_reported_with_the_available_names(): void
    {
        $this->web()->registrar()->get('leads', [\stdClass::class, 'index'])->name('leads.index');
        $this->web()->flush();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/No route named \[leads\.typo\] is registered/');
        $this->expectExceptionMessageMatches('/leads\.index/');

        $this->generator->route('leads.typo');
    }

    /**
     * **A name declared in both domains is refused, not resolved by luck.**
     *
     * Two routes may share a name — the router tolerates it and `route:list`
     * reports it — but a URL cannot: `route('leads')` has exactly one answer, and
     * picking whichever table registered first makes a link point at the wrong
     * screen. A wrong link that works is worse than an exception, because nobody
     * investigates it.
     */
    public function test_a_name_declared_in_both_domains_is_refused_as_ambiguous(): void
    {
        $this->web()->registrar()->get('leads', [\stdClass::class, 'index'])->name('leads');
        $this->api()->registrar()->get('leads', [\stdClass::class, 'index'])->name('leads');

        $this->web()->flush();
        $this->api()->flush();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is ambiguous/');

        $this->generator->route('leads');
    }

    // ----------------------------------------------------------- live registry

    /**
     * **A route registered after the generator was built is still found.**
     *
     * This is the property the whole {@see NamedRoutes} indirection exists for. The
     * generator is a container singleton resolved by a template during dispatch,
     * but the two tables fill at different moments — `admin_menu` on an admin
     * request, `rest_api_init` on a REST one — so a generator holding a *copy* of
     * the routes would be correct only for whichever domain loaded first, and
     * quietly wrong for the other.
     */
    public function test_a_route_registered_after_the_generator_was_built_is_found(): void
    {
        $this->assertFalse($this->generator->has('late.route'));

        $this->web()->registrar()->get('late', [\stdClass::class, 'index'])->name('late.route');
        $this->web()->flush();

        $this->assertTrue($this->generator->has('late.route'));
        $this->assertSame(self::ADMIN_BASE . 'admin.php?page=late', $this->generator->route('late.route'));
    }

    /**
     * And the second domain is reachable from the same generator after it loads.
     *
     * The generator is built once in `setUp()` and never rebuilt, so both
     * assertions passing is what shows the registry is read through rather than
     * snapshotted.
     */
    public function test_both_domains_are_reachable_from_one_generator(): void
    {
        $this->web()->registrar()->get('leads', [\stdClass::class, 'index'])->name('web.leads');
        $this->web()->flush();

        $this->assertSame(self::ADMIN_BASE . 'admin.php?page=leads', $this->generator->route('web.leads'));

        $this->api()->registrar()->get('leads', [\stdClass::class, 'index'])->name('api.leads');
        $this->api()->flush();

        $this->assertSame('wp-mini-laravel/v1/leads', $this->generator->route('api.leads'));
    }

    /**
     * A flush that runs twice does not make every name look duplicated.
     *
     * The web hooks each load and flush, and the adapter's own guard is what makes
     * that safe. If the registry recorded the router again, every name it declares
     * would appear in both a first and a second pass — and an ordinary page load
     * would report every route as ambiguous.
     */
    public function test_a_repeated_flush_does_not_report_every_name_as_ambiguous(): void
    {
        $this->web()->registrar()->get('leads', [\stdClass::class, 'index'])->name('leads.index');

        $this->web()->flush();
        $this->web()->flush();

        $this->assertSame(
            self::ADMIN_BASE . 'admin.php?page=leads',
            $this->generator->route('leads.index')
        );
    }

    /**
     * A self-referencing redirect works end to end.
     *
     * The property a route:list entry cannot show: a controller can send the user
     * to a *name*, so renaming a screen changes one file and every link follows.
     */
    public function test_a_controller_can_redirect_to_a_route_by_name(): void
    {
        $this->web()->registrar()
            ->post(LeadControllerFixture::STORE_PATH, [\stdClass::class, 'store'])
            ->name('fixture.leads.store');
        $this->web()->flush();

        $this->assertSame(
            self::ADMIN_BASE . 'admin-post.php?action=fixture_leads_store',
            $this->generator->route(LeadControllerFixture::STORE_NAME)
        );
    }

    // ----------------------------------------------------------------- helpers

    /**
     * The web adapter sharing this test's registry.
     */
    private function web(): WordPressRouterAdapter
    {
        return $this->web;
    }

    /**
     * The API adapter sharing this test's registry.
     *
     * The adapter rather than {@see \Framework\Routing\Router} — both register
     * identically for URL purposes, and the adapter is what a project holding
     * `router.api` gets, so it is what these tests exercise.
     */
    private function api(): RestApiRouterAdapter
    {
        return $this->api;
    }

    /**
     * A bare router holding routes that were already built, for the compiled-path
     * and no-surface cases.
     *
     * `$router->add()` rather than the registrar's methods, because the routes here
     * were built by hand rather than declared through a verb — there is no registrar
     * call that would produce a compound regex path, or one that deliberately omits
     * a surface. Variadic so the two inferences in a single test share a router: two
     * routers in one test would be registered under the same domain label, and the
     * second would be skipped as an idempotent repeat.
     */
    private function routerWith(Route ...$routes): Router
    {
        $router = new Router($this->container, new RouteResolver($this->container), $this->named);

        foreach ($routes as $route) {
            $router->add($route);
        }

        return $router;
    }
}

/**
 * The constants a controller would expose for its own route, so a redirect can
 * name the route without repeating the literal.
 */
final class LeadControllerFixture
{
    public const STORE_PATH = 'fixture/leads/store';

    public const STORE_NAME = 'fixture.leads.store';
}
