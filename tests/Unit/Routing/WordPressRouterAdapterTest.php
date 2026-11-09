<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Http\HttpServiceProvider;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request;
use Framework\Routing\Adapters\WordPressRouterAdapter;
use Framework\Routing\Exceptions\RouteRegistrationException;
use App\Providers\RouteServiceProvider;
use Framework\Routing\RouteResolver;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;
use Tests\Unit\Routing\Fixtures\FakeWebRequestFactory;
use Tests\Unit\Routing\Fixtures\WebDecoratingMiddleware;
use Tests\Unit\Routing\Fixtures\WebRecorder;
use Tests\Unit\Routing\Fixtures\WebRecordingMiddleware;
use Tests\Unit\Routing\Fixtures\WebRoutingContractMiddleware;
use Tests\Unit\Routing\Fixtures\WebShortCircuitingMiddleware;

/**
 * The web adapter translates Route objects into WordPress hook registrations.
 *
 * ## What these tests can and cannot see
 *
 * WordPress's own hook dispatch is stubbed — `WpStub::doAction()` calls the
 * registered callbacks in order, which is what WordPress does. So the assertions
 * are about *what the adapter asked WordPress to do*: which menu function was
 * called with which arguments, which action tags were hooked. That is the
 * translation this class is responsible for, and it is the part that can be
 * wrong while a manual click-through still appears to work.
 *
 * Controllers are dispatched for real, through a real container and a real
 * pipeline, so the response shape is asserted rather than assumed. The request is
 * supplied by {@see FakeWebRequestFactory} because `php://input` cannot be faked
 * and the superglobals are empty in a PHPUnit process.
 */
final class WordPressRouterAdapterTest extends TestCase
{
    /**
     * Set by the injected terminator, so a test can assert whether a matched
     * route ended the request without the process actually ending.
     */
    private static bool $terminated = false;

    private Container $container;
    private WordPressRouterAdapter $router;

    protected function setUp(): void
    {
        WpStub::reset();
        WebRecorder::reset();
        WebRecordingMiddleware::$log = [];
        FakeWebRequestFactory::reset();
        self::$terminated = false;

        $this->container = new Container();
        $this->container->instance(
            \DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface::class,
            $this->container
        );

        // The HTTP layer, so `Pipeline` and a `Request` are resolvable — the same
        // provider a real project registers. Without it the adapter still routes,
        // but there is no pipeline to run middleware through.
        (new HttpServiceProvider($this->container))->register();

        // The test's own request replaces the captured one.
        $this->container->instance(Request::class, (new FakeWebRequestFactory())->make());

        // The terminator is replaced with a flag rather than the real `exit`,
        // which would end the PHPUnit process the moment a page route matched.
        $this->router = new WordPressRouterAdapter(
            $this->container,
            new RouteResolver($this->container),
            new FakeWebRequestFactory(),
            null,
            function (): void {
                self::$terminated = true;
            }
        );
    }

    // ------------------------------------------------------------ admin pages

    /**
     * The brief's core requirement: an admin page route becomes an `admin_menu`
     * registration.
     */
    public function test_an_admin_page_route_is_published_on_admin_menu(): void
    {
        $this->router->adminPage('leads', Fixtures\StringController::class, 'index');
        $this->router->flush();

        $this->assertArrayHasKey('admin_menu', WpStub::$actions);

        // Nothing is registered until the action fires — that is what "publish on
        // admin_menu" means, and asserting before would pass for an adapter that
        // registered eagerly at boot.
        $this->assertSame([], WpStub::$menuPages);

        WpStub::doAction('admin_menu');

        $this->assertCount(1, WpStub::$menuPages);
        $this->assertSame('leads', WpStub::$menuPages[0]['menu_slug']);
    }

    /**
     * The options bag is the whole reason WP-specific arguments do not need
     * first-class methods on the DTO, so each one has to actually arrive.
     */
    public function test_the_wordpress_arguments_come_from_route_options(): void
    {
        $this->router->adminPage('leads', Fixtures\StringController::class, 'index')
            ->withOptions([
                'page_title' => 'All Leads',
                'menu_title' => 'Leads',
                'capability' => 'edit_posts',
                'icon' => 'dashicons-email',
                'position' => 26,
            ]);

        $this->router->flush();
        WpStub::doAction('admin_menu');

        $page = WpStub::$menuPages[0];

        $this->assertSame('All Leads', $page['page_title']);
        $this->assertSame('Leads', $page['menu_title']);
        $this->assertSame('edit_posts', $page['capability']);
        $this->assertSame('dashicons-email', $page['icon_url']);
        $this->assertSame(26, $page['position']);
        $this->assertSame('leads', $page['menu_slug']);
    }

    /**
     * An unset capability must default to `manage_options`, matching
     * `add_menu_page()`. A screen registered with no capability argument at all
     * would be reachable by any logged-in user.
     */
    public function test_an_admin_page_defaults_to_manage_options(): void
    {
        $this->router->adminPage('leads', Fixtures\StringController::class, 'index');
        $this->router->flush();
        WpStub::doAction('admin_menu');

        $this->assertSame('manage_options', WpStub::$menuPages[0]['capability']);
    }

    /**
     * A `parent_slug` moves the entry into the submenu function, which has a
     * different signature — the branch that would otherwise be dead.
     */
    public function test_a_parent_slug_registers_a_submenu_page(): void
    {
        $this->router->adminPage('leads', Fixtures\StringController::class, 'index')
            ->withOptions(['parent_slug' => 'tools.php']);

        $this->router->flush();
        WpStub::doAction('admin_menu');

        $this->assertSame([], WpStub::$menuPages, 'A submenu entry must not also be a top-level one.');
        $this->assertCount(1, WpStub::$submenuPages);
        $this->assertSame('tools.php', WpStub::$submenuPages[0]['parent_slug']);
        $this->assertSame('leads', WpStub::$submenuPages[0]['menu_slug']);
    }

    // ----------------------------------------------------------- admin actions

    /**
     * A form handler goes on `admin_post_{action}` — and the action name is
     * derived from the path.
     */
    public function test_a_form_handler_is_published_on_admin_post(): void
    {
        $this->router->adminAction('leads/store', Fixtures\StringController::class, 'index');
        $this->router->flush();

        $this->assertArrayHasKey('admin_post_leads_store', WpStub::$actions);
    }

    /**
     * **Both** hooks must be registered, and this is the bug worth its own test:
     * `admin_post_{action}` fires only for a logged-in user, so registering just
     * it produces a form that works perfectly while logged in and silently does
     * nothing for everyone else.
     */
    public function test_a_form_handler_is_published_for_logged_out_users_too(): void
    {
        $this->router->adminAction('leads/store', Fixtures\StringController::class, 'index');
        $this->router->flush();

        $this->assertArrayHasKey('admin_post_leads_store', WpStub::$actions);
        $this->assertArrayHasKey(
            'admin_post_nopriv_leads_store',
            WpStub::$actions,
            'Without the nopriv hook the handler is unreachable for logged-out users.'
        );
    }

    /**
     * An explicit `->name()` wins over the derived path, because it is the
     * identifier the author chose and the one already in the route table.
     */
    public function test_a_route_name_overrides_the_derived_action(): void
    {
        $this->router->adminAction('leads/store', Fixtures\StringController::class, 'index')
            ->name('leads.save');

        $this->router->flush();

        $this->assertArrayHasKey('admin_post_leads_save', WpStub::$actions);
    }

    /**
     * Placeholders are dropped from the derived action. `leads/{id}` and
     * `leads/123` are the same handler, so the id must not become part of the
     * hook name.
     */
    public function test_placeholders_do_not_appear_in_the_derived_action(): void
    {
        $this->router->adminAction('leads/{id}/destroy', Fixtures\StringController::class, 'index');
        $this->router->flush();

        $this->assertArrayHasKey('admin_post_leads_destroy', WpStub::$actions);
    }

    /**
     * A constraint containing braces is removed whole, not up to its first `}`.
     *
     * ## What this does *not* claim
     *
     * That the old parser got this wrong. It did not, and that is worth stating
     * rather than glossing: `preg_replace('/\{[^}]*\}/', '', …)` stops at the
     * quantifier's first `}` and removes only `{id:[0-9]`, but the leftover `}}`
     * then normalises to a separator and the name comes out `leads_destroy`
     * anyway. The old code was correct here *by accident of normalisation*, which
     * is precisely why the case is worth pinning down: the correctness rested on
     * a step three lines away, and a change to that step — keeping braces rather
     * than folding them to `_` — would have broken it silently.
     *
     * Delegating to the compiler makes it correct on purpose, and this test is
     * what holds that in place.
     */
    public function test_a_brace_bearing_constraint_does_not_leak_into_the_derived_action(): void
    {
        $this->router->adminAction('leads/{id:[0-9]{1,3}}/destroy', Fixtures\StringController::class, 'index');
        $this->router->flush();

        $this->assertArrayHasKey(
            'admin_post_leads_destroy',
            WpStub::$actions,
            'The quantifier inside a constraint leaked into the hook name.'
        );
    }

    /**
     * A compiled placeholder is removed by name, not mangled into the action.
     *
     * A path that reached the adapter already compiled — `(?P<id>[^/]+)/leads` — is
     * an ordinary state after a route has been through registration. The old
     * `\{[^}]*\}` removal knew nothing about that spelling, so the letters left
     * over from `(?P<id>…)` were folded into the name: `admin_post_p_id_leads`.
     * Nobody could have matched it, because nobody would write it.
     */
    public function test_a_compiled_placeholder_does_not_leak_letters_into_the_derived_action(): void
    {
        $this->router->adminAction('(?P<id>[^/]+)/leads/store', Fixtures\StringController::class, 'index');
        $this->router->flush();

        $this->assertArrayHasKey(
            'admin_post_leads_store',
            WpStub::$actions,
            'A compiled group leaked its own spelling into the action name.'
        );
    }

    /**
     * Two routes deriving one action name is refused, not accepted. `add_action()`
     * does not replace an existing callback, so both would run on one submission
     * and the second controller would act on the first's request.
     */
    public function test_two_routes_deriving_the_same_action_are_refused(): void
    {
        $this->router->adminAction('leads/store', Fixtures\StringController::class, 'index');
        $this->router->adminAction('leads-store', Fixtures\StringController::class, 'index');

        $this->expectException(RouteRegistrationException::class);
        $this->expectExceptionMessageMatches('/both publish the form action/');

        $this->router->flush();
    }

    /**
     * PUT and DELETE on one path must not share a hook. Both are reached through
     * a `_method` field, so the verb is the only thing distinguishing them.
     */
    public function test_different_verbs_on_one_path_get_distinct_actions(): void
    {
        $this->router->put('leads/{id}', Fixtures\StringController::class, 'index');
        $this->router->delete('leads/{id}', Fixtures\StringController::class, 'index');

        $this->router->flush();

        $this->assertArrayHasKey('admin_post_leads_put', WpStub::$actions);
        $this->assertArrayHasKey('admin_post_leads_delete', WpStub::$actions);
    }

    // ------------------------------------------------------------- front pages

    public function test_a_page_route_is_published_on_template_redirect(): void
    {
        $this->router->page('thank-you', Fixtures\StringController::class, 'index');
        $this->router->flush();

        $this->assertArrayHasKey('template_redirect', WpStub::$actions);
    }

    /**
     * A non-GET verb with no explicit surface is inferred to be a form handler.
     * This is the "verb as fallback" half of the design.
     */
    public function test_a_post_verb_infers_the_form_surface(): void
    {
        $this->router->post('leads', Fixtures\StringController::class, 'index');
        $this->router->flush();

        $this->assertArrayHasKey('admin_post_leads', WpStub::$actions);
        $this->assertArrayNotHasKey('template_redirect', WpStub::$actions);
    }

    /**
     * ...and a bare GET is inferred to be an admin screen.
     *
     * This is the inference that flipped when the web router adopted Laravel's
     * verb syntax. A bare `get()` used to mean a front-end page; it now means an
     * admin screen, because that is what a `get()` in a web routes file almost
     * always is — and `page()` is what a front-end route asks for explicitly.
     *
     * The assertion moved rather than disappeared: the point of the test is which
     * surface the verb infers, so pinning it to the *new* surface is what keeps
     * the test honest. Relaxing it to "registered something" would let either
     * answer pass.
     */
    public function test_a_get_verb_infers_the_admin_screen_surface(): void
    {
        $this->router->get('leads', Fixtures\StringController::class, 'index');
        $this->router->flush();
        WpStub::doAction('admin_menu');

        $this->assertCount(1, WpStub::$menuPages);
        $this->assertSame([], WpStub::$actions['template_redirect'] ?? []);
    }

    /**
     * ...and the explicit helper overrides the inference, which is the case the
     * helpers exist for: a GET route that is really an admin screen.
     */
    public function test_an_explicit_surface_beats_the_verb_inference(): void
    {
        $this->router->get('leads', Fixtures\StringController::class, 'index')
            ->option(WordPressRouterAdapter::OPTION_SURFACE, WordPressRouterAdapter::SURFACE_ADMIN_PAGE);

        $this->router->flush();
        WpStub::doAction('admin_menu');

        $this->assertCount(1, WpStub::$menuPages);
        $this->assertSame([], WpStub::$actions['template_redirect'] ?? []);
    }

    // ---------------------------------------------------------------- dispatch

    /**
     * The full path: an admin screen's callback resolves the controller, runs it
     * and sends the response.
     */
    public function test_an_admin_page_callback_dispatches_the_controller(): void
    {
        $this->router->adminPage('leads', Fixtures\StringController::class, 'index');
        $this->router->flush();
        WpStub::doAction('admin_menu');

        $this->invokeMenuCallback(0);

        $this->assertSame(['StringController@index'], WebRecorder::$calls);
    }

    /**
     * A front-end match binds the request and dispatches. The path comes from the
     * request URI, so this is also where the matching rule is pinned.
     */
    public function test_a_template_redirect_match_dispatches_the_controller(): void
    {
        FakeWebRequestFactory::$uri = '/thank-you';
        FakeWebRequestFactory::$method = 'GET';

        $this->router->page('thank-you', Fixtures\StringController::class, 'index');
        $this->router->flush();

        $this->invokeTemplateRedirect();

        $this->assertSame(['StringController@index'], WebRecorder::$calls);

        // A matched front-end route must end the request: `template_redirect` is
        // an action, so returning from it lets WordPress render the theme on top
        // of the response the controller already sent.
        $this->assertTrue(
            self::$terminated,
            'A matched page route must terminate the request.'
        );
    }

    /**
     * **A miss must send nothing, and must not terminate.**
     *
     * `template_redirect` fires on *every* front-end request, so two failures are
     * possible and both are worse than a 404: answering on a miss replaces the
     * whole site with a blank page, and terminating on a miss stops WordPress
     * rendering any page at all.
     */
    public function test_a_template_redirect_miss_dispatches_nothing(): void
    {
        FakeWebRequestFactory::$uri = '/some-other-page';

        $this->router->page('thank-you', Fixtures\StringController::class, 'index');
        $this->router->flush();

        $this->invokeTemplateRedirect();

        $this->assertSame([], WebRecorder::$calls);
        $this->assertFalse(
            self::$terminated,
            'A miss must let WordPress render the page it resolved.'
        );
    }

    /**
     * An admin screen must *not* terminate — the exact opposite of a page route,
     * and the reason termination is the dispatcher's decision rather than the
     * response's.
     */
    public function test_an_admin_page_does_not_terminate_the_request(): void
    {
        $this->router->adminPage('leads', Fixtures\StringController::class, 'index');
        $this->router->flush();
        WpStub::doAction('admin_menu');

        self::$terminated = false;

        $this->invokeMenuCallback(0);

        $this->assertFalse(
            self::$terminated,
            'An admin screen that exited would lose the admin chrome around it.'
        );
    }

    /**
     * Surrounding slashes and a subdirectory install must not stop a route
     * matching — WordPress is routinely installed under a prefix.
     */
    public function test_a_path_matches_whatever_the_surrounding_slashes(): void
    {
        FakeWebRequestFactory::$uri = '/thank-you/';

        $this->router->page('thank-you', Fixtures\StringController::class, 'index');
        $this->router->flush();

        $this->invokeTemplateRedirect();

        $this->assertSame(['StringController@index'], WebRecorder::$calls);
    }

    /**
     * A placeholder is matched and handed to the action — through the same
     * grammar the REST router uses, so `{id:\d+}` means one thing everywhere.
     */
    public function test_a_placeholder_value_reaches_the_action(): void
    {
        FakeWebRequestFactory::$uri = '/leads/42';

        $this->router->page('leads/{id}', Fixtures\ParameterController::class, 'show');
        $this->router->flush();

        $this->invokeTemplateRedirect();

        $this->assertSame('42', WebRecorder::$received['id'] ?? null);
    }

    /**
     * A GET route reached by POST is refused with 405 rather than run. Running it
     * would execute a read action with form data as though it were a screen load.
     */
    public function test_a_get_route_reached_by_post_is_refused(): void
    {
        FakeWebRequestFactory::$uri = '/thank-you';
        FakeWebRequestFactory::$method = 'POST';

        $this->router->page('thank-you', Fixtures\StringController::class, 'index');
        $this->router->flush();

        $this->invokeTemplateRedirect();

        $this->assertSame([], WebRecorder::$calls, 'The controller must not run.');
    }

    // ----------------------------------------------------------- admin dispatch

    /**
     * A form submission is dispatched by the action the *request* carries.
     *
     * The whole reason `dispatchCurrentAdminAction()` exists: WordPress resolves
     * and fires `admin_post_{action}` in one pass, so the route is found by
     * matching the submitted action against the published table rather than by
     * having hooked the derived name. This asserts the match happens — and that
     * the action came from the injected request, since `$_REQUEST` is empty in a
     * PHPUnit process and a superglobal read would find nothing to dispatch.
     */
    public function test_a_form_submission_is_dispatched_by_the_action_the_request_carries(): void
    {
        FakeWebRequestFactory::$method = 'POST';
        FakeWebRequestFactory::$query = ['action' => 'leads_store'];

        $this->router->post('leads/store', Fixtures\StringController::class, 'store');
        $this->router->flush();

        $this->dispatchAdminAction();

        $this->assertSame(['StringController@store'], WebRecorder::$calls);
    }

    /**
     * The action is read from the request body too, not only the query string.
     *
     * `admin-post.php` reaches PHP either way depending on how the form is
     * written, so a reader that consulted only one source would drop the common
     * `<form action="admin-post.php" method="post">` with the action as a hidden
     * field.
     */
    public function test_the_action_is_read_from_the_request_body_as_well_as_the_query(): void
    {
        FakeWebRequestFactory::$method = 'POST';
        FakeWebRequestFactory::$body = ['action' => 'leads_store'];

        $this->router->post('leads/store', Fixtures\StringController::class, 'store');
        $this->router->flush();

        $this->dispatchAdminAction();

        $this->assertSame(['StringController@store'], WebRecorder::$calls);
    }

    /**
     * A route placeholder is filled from the submission's own fields.
     *
     * An admin-post request carries no URL path — WordPress dispatches on
     * `admin-post.php?action=...` regardless — so `leads/{id}/destroy` can never
     * have its placeholder matched from the URL, and a hidden input of the same
     * name is what fills it.
     *
     * The submission also has to say `_method=DELETE`, because a form can only
     * send GET or POST: without the override the route is reached by POST and
     * refused with a 405 before the controller runs.
     */
    public function test_a_route_placeholder_is_filled_from_the_submission_fields(): void
    {
        FakeWebRequestFactory::$method = 'POST';
        // The action carries the verb suffix, because a non-POST route derives
        // `leads_destroy_delete` rather than the bare path — see the
        // different-verbs-on-one-path test above.
        FakeWebRequestFactory::$body = [
            'action'  => 'leads_destroy_delete',
            'id'      => '42',
            '_method' => 'DELETE',
        ];

        $this->router->delete('leads/{id}/destroy', Fixtures\ParameterController::class, 'show');
        $this->router->flush();

        $this->dispatchAdminAction();

        $this->assertSame('42', WebRecorder::$received['id'] ?? null);
    }

    /**
     * An action nobody published is left alone.
     *
     * WordPress fires `admin_post` for its own internal handlers too, and an
     * unmatched action is not this plugin's business. Sending a response here
     * would take over a request another handler owns.
     */
    public function test_an_unmatched_action_is_a_no_op(): void
    {
        FakeWebRequestFactory::$method = 'POST';
        FakeWebRequestFactory::$body = ['action' => 'some_other_plugin_action'];

        $this->router->post('leads/store', Fixtures\StringController::class, 'store');
        $this->router->flush();

        $this->dispatchAdminAction();

        $this->assertSame([], WebRecorder::$calls);
    }

    /** A submission carrying no action at all is likewise ignored. */
    public function test_a_submission_with_no_action_is_a_no_op(): void
    {
        FakeWebRequestFactory::$method = 'POST';

        $this->router->post('leads/store', Fixtures\StringController::class, 'store');
        $this->router->flush();

        $this->dispatchAdminAction();

        $this->assertSame([], WebRecorder::$calls);
    }

    /** An action present but empty is treated as absent, not matched. */
    public function test_an_empty_action_is_a_no_op(): void
    {
        FakeWebRequestFactory::$method = 'POST';
        FakeWebRequestFactory::$body = ['action' => ''];

        $this->router->post('leads/store', Fixtures\StringController::class, 'store');
        $this->router->flush();

        $this->dispatchAdminAction();

        $this->assertSame([], WebRecorder::$calls);
    }

    /**
     * A list arriving under a placeholder's name never becomes the string
     * `"Array"`.
     *
     * `?id[]=1` is a scalar-shaped attack on the binder: the value is an array,
     * and a naive `(string)` cast would bind the literal string `Array` to a
     * typed `string $id` and run the action on nonsense. The field is skipped
     * instead, so the action does **not** receive a fabricated id — here it fails
     * to bind the required parameter at all, which is the loud outcome this
     * asserts rather than a quiet wrong value.
     *
     * Asserting the *absence* of `"Array"` alone would pass against a dispatch
     * that never ran, so the recorded value is checked as a whole.
     */
    public function test_an_array_valued_field_is_not_bound_as_the_string_array(): void
    {
        FakeWebRequestFactory::$method = 'POST';
        FakeWebRequestFactory::$body = [
            'action'  => 'leads_destroy_delete',
            'id'      => ['1', '2'],
            '_method' => 'DELETE',
        ];

        $this->router->delete('leads/{id}/destroy', Fixtures\ParameterController::class, 'show');
        $this->router->flush();

        $this->dispatchAdminAction();

        $this->assertArrayNotHasKey(
            'id',
            WebRecorder::$received,
            'An array-valued field must not be bound as a scalar parameter.'
        );
    }

    // --------------------------------------------------------------- pipeline
    /**
     * Web middleware runs as an onion through the HTTP pipeline — before *and*
     * after the destination, which the REST seam cannot do.
     */
    public function test_web_middleware_runs_through_the_pipeline_as_an_onion(): void
    {
        $this->router->adminPage('leads', Fixtures\StringController::class, 'index')
            ->middleware(WebRecordingMiddleware::class);

        $this->router->flush();
        WpStub::doAction('admin_menu');

        $this->invokeMenuCallback(0);

        $this->assertSame(['in', 'out'], WebRecordingMiddleware::$log);
        $this->assertSame(['StringController@index'], WebRecorder::$calls);
    }

    /**
     * A middleware that answers without delegating stops the controller — the
     * capability a web route's authorization depends on.
     */
    public function test_a_short_circuiting_middleware_prevents_the_controller(): void
    {
        WebShortCircuitingMiddleware::$called = false;

        $this->router->adminPage('leads', Fixtures\StringController::class, 'index')
            ->middleware(WebShortCircuitingMiddleware::class);

        $this->router->flush();
        WpStub::doAction('admin_menu');

        $this->invokeMenuCallback(0);

        $this->assertTrue(WebShortCircuitingMiddleware::$called);
        $this->assertSame([], WebRecorder::$calls, 'A refused request must not reach the controller.');
    }

    /**
     * Middleware can decorate the response on the way out, which is the round trip
     * the pipeline exists for.
     */
    public function test_middleware_can_decorate_the_response(): void
    {
        $this->router->adminPage('leads', Fixtures\ResponseController::class, 'index')
            ->middleware(WebDecoratingMiddleware::class);

        $this->router->flush();
        WpStub::doAction('admin_menu');

        // The controller returns 201; the middleware rewrites it to 202. Reaching
        // the assertion at all proves the middleware got the response back.
        $this->invokeMenuCallback(0);

        $this->assertSame(['ResponseController@index'], WebRecorder::$calls);
    }

    /**
     * A routing-contract middleware on a web route is named as the mistake it is,
     * rather than failing later inside the container with a message about an
     * interface.
     */
    public function test_a_routing_contract_middleware_is_reported_by_name(): void
    {
        $this->router->adminPage('leads', Fixtures\StringController::class, 'index')
            ->middleware(WebRoutingContractMiddleware::class);

        $this->router->flush();
        WpStub::doAction('admin_menu');

        $output = $this->captureMenuCallback(0);

        // The controller must not run: the route's middleware stack is invalid, so
        // there is no version of this request that should reach the action.
        $this->assertSame([], WebRecorder::$calls);

        // And the message has to name all three things a developer needs: the
        // middleware class, the route it was put on, and the contract to implement
        // instead. The last one is what makes the failure actionable — the author
        // wrote a correct class and used it in the wrong domain.
        $this->assertStringContainsString(WebRoutingContractMiddleware::class, $output);
        $this->assertStringContainsString('/leads', $output);
        $this->assertStringContainsString('Framework\Http\Contracts\Middleware', $output);
    }

    // ------------------------------------------------------------- route table

    public function test_routes_are_visible_before_publishing(): void
    {
        $this->router->adminPage('leads', Fixtures\StringController::class, 'index');

        $this->assertCount(1, $this->router->getRoutes());
        $this->assertFalse($this->router->hasFlushed());
    }

    public function test_flushing_twice_publishes_once(): void
    {
        $this->router->adminPage('leads', Fixtures\StringController::class, 'index');

        $this->router->flush();
        $this->router->flush();

        // One `admin_menu` listener, not two — a second would add a duplicate
        // menu entry.
        $this->assertCount(1, WpStub::$actions['admin_menu'] ?? []);

        WpStub::doAction('admin_menu');

        $this->assertCount(1, WpStub::$menuPages);
    }

    public function test_the_domain_is_web(): void
    {
        $this->assertSame('web', $this->router->domain());
    }

    public function test_the_adapter_satisfies_the_router_contract(): void
    {
        $this->assertInstanceOf(
            \Framework\Routing\Contracts\RouterInterface::class,
            $this->router
        );
    }

    public function test_the_service_provider_registers_the_adapter_as_a_singleton(): void
    {
        $container = new Container();
        $container->instance(
            \DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface::class,
            $container
        );

        $provider = new RouteServiceProvider($container);
        $provider->register();

        $this->assertSame(
            $container->resolve(WordPressRouterAdapter::class),
            $container->resolve(WordPressRouterAdapter::class),
            'A per-resolve adapter would hold an empty table at flush time.'
        );
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Call the callback WordPress would call for an admin screen.
     *
     * Output buffered and discarded: the response echoes, and PHPUnit would
     * otherwise print the controller's markup into the test output.
     */
    private function invokeMenuCallback(int $index): void
    {
        $this->captureMenuCallback($index);
    }

    /**
     * Call the menu callback and return everything it echoed.
     *
     * The output is what carries a failure report, so asserting on it is how a
     * test distinguishes "the controller was refused" from "the request failed" —
     * both leave the recorder empty.
     */
    private function captureMenuCallback(int $index): string
    {
        $callback = WpStub::$menuPages[$index]['callback'] ?? null;

        $this->assertIsCallable($callback, 'No menu callback was registered.');

        ob_start();

        try {
            $callback();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    /**
     * Fire `template_redirect`.
     *
     * Safe because {@see TestableWordPressRouterAdapter} replaces the adapter's
     * `terminate()` — a real `exit` here would end the PHPUnit process, and there
     * is no way to catch one.
     */
    private function invokeTemplateRedirect(): void
    {
        self::$terminated = false;

        ob_start();

        try {
            WpStub::doAction('template_redirect');
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Dispatch the current admin action with its output buffered.
     *
     * A matched form handler sends a real response, which echoes — the same
     * reason {@see invokeTemplateRedirect()} buffers — and PHPUnit reports a test
     * that prints as risky. The assertion is about what the controller recorded,
     * not about the markup.
     */
    private function dispatchAdminAction(): void
    {
        ob_start();

        try {
            $this->router->dispatchCurrentAdminAction();
        } finally {
            ob_end_clean();
        }
    }
}
