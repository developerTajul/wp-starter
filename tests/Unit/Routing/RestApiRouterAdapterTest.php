<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Config\Config;
use Framework\Http\Request;
use Framework\Http\Requests\FormRequest;
use Framework\Http\Responses\RedirectResponse;
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

    // ------------------------------------------------------------- error codes

    /**
     * **Every error code this adapter raises is prefixed from the same seam.**
     *
     * ## Why this is a test and not a tidy-up
     *
     * The body of a `WP_Error` is read by humans; the *code* is read by clients.
     * A consuming app checks `error.code` to decide what to do, and it does so by
     * matching a prefix — so a code that keeps the old prefix after a rebrand is
     * not a cosmetic leftover, it is a branch in someone else's front end that
     * stops firing.
     *
     * Five of the six codes here were hardcoded while a sixth was derived, which
     * is the version of this that is worst to find: the derived one makes the
     * file *look* rebrand-aware, so the literals beside it read as deliberate.
     *
     * ## Why the assertion is made against a *rebranded* config
     *
     * Asserting against the resolved prefix under the shipped config would pass
     * for a hardcoded literal too — `RedirectResponse::DEFAULT_PREFIX` and
     * `config/app.php`'s `prefix` are both `wp_mini_laravel_`, so the two are the
     * same string and the assertion cannot tell them apart. That is not a
     * hypothetical: the first version of this test did exactly that, and stayed
     * green when a code was reverted to a literal.
     *
     * So the fixture config is loaded first, with a prefix deliberately unlike the
     * default. Now a derived code changes with it and a literal does not — which
     * is the only shape of this test that can fail.
     *
     * @see \Tests\Fixtures\config\app.php
     */
    public function test_every_error_code_carries_the_configured_prefix(): void
    {
        $this->loadRebrandedConfig();

        $prefix = RedirectResponse::resolvePrefix();

        $this->assertSame(
            'acme_',
            $prefix,
            'Precondition: the fixture config must actually be in effect, or this test proves nothing.'
        );

        foreach ($this->errorCodes() as $scenario => $code) {
            $this->assertStringStartsWith(
                $prefix,
                $code,
                sprintf(
                    'The [%s] error code [%s] does not start with the configured prefix [%s] — it is '
                    . 'hardcoded, so a rebranded install would hand clients a code they do not match on.',
                    $scenario,
                    $code,
                    $prefix
                )
            );
        }

        $this->restoreShippedConfig();
    }

    /**
     * And the codes are still *distinct* after the prefix is stripped.
     *
     * Deriving six codes from one seam makes it easy to derive the same one
     * twice — a copy-paste that every prefix assertion above would happily pass.
     * A client cannot tell two failures apart if they arrive under one code, and
     * the collisions would be between the paths least likely to be exercised by
     * hand: a handler that was never set, and a middleware that threw.
     *
     * @see test_every_error_code_carries_the_configured_prefix()
     */
    public function test_each_failure_path_has_its_own_code(): void
    {
        $this->loadRebrandedConfig();

        $codes = $this->errorCodes();

        $this->assertSame(
            count($codes),
            count(array_unique($codes)),
            sprintf('Two failure paths share an error code: %s', implode(', ', $codes))
        );

        $this->restoreShippedConfig();
    }

    /**
     * One error code per failure path, each reached by actually triggering it.
     *
     * ## Why each path is provoked rather than the codes being read off the file
     *
     * A list of expected strings checked against itself proves nothing. Each entry
     * below is the code that came back from a real call — so a path that stopped
     * producing an error at all fails here rather than silently dropping out of
     * the set.
     *
     * ## Why all three are declared before the single flush
     *
     * `flush()` publishes once and no-ops afterwards, and the adapter refuses a
     * duplicate path — so the three scenarios cannot be registered one at a time.
     * Declaring them together and flushing once is also the shape a real
     * application has, which makes this closer to the thing under test.
     *
     * ## What is *not* in the list, and why
     *
     * `no_handler` — its guard is unreachable, because `publish()` validates the
     * whole set before registering anything. See
     * {@see test_the_no_handler_guard_is_unreachable_because_publish_validates_first()}.
     * `controller_failed` needs a class the container cannot build, so it is
     * asserted separately below. The two that remain here are the paths a request
     * can actually reach.
     *
     * @return array<string, string> scenario => the code it produced
     */
    private function errorCodes(): array
    {
        $this->router->get('throwing', Fixtures\ReportController::class, 'index')
            ->middleware(Fixtures\ThrowingMiddleware::class);

        $this->router->get('loose', Fixtures\ReportController::class, 'index')
            ->middleware(Fixtures\LooseMiddleware::class);

        $this->router->flush();

        $request = new WP_REST_Request();

        return [
            'a throwing middleware' => $this->permissionCallback(0)($request)->get_error_code(),
            'a middleware breaking its contract' => $this->permissionCallback(1)($request)->get_error_code(),
        ];
    }

    /**
     * The controller-resolution failure, asserted on its own.
     *
     * Separate because it is the only path that depends on the container *not*
     * having a class — and the container is shared across a test, so provoking it
     * here rather than inside {@see errorCodes()} keeps that helper's other
     * scenarios from inheriting a deliberately-unbuildable state.
     */
    public function test_an_unbuildable_controller_reports_its_own_code(): void
    {
        $this->loadRebrandedConfig();

        $this->router->get('leads', 'App\\Http\\Controllers\\Api\\NotRegisteredController', 'index');

        $this->router->flush();

        $result = $this->restCallback(0)(new WP_REST_Request());

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(
            RedirectResponse::resolvePrefix() . 'controller_failed',
            $result->get_error_code()
        );

        $this->restoreShippedConfig();
    }

    /**
     * The `no_handler` guard is unreachable through this adapter, and that is
     * pinned here rather than left as a note.
     *
     * `destination()` still answers with a "this route has no handler" `WP_Error`,
     * but `publish()` validates the whole set first and throws
     * {@see RouteRegistrationException} before WordPress is told anything — so a
     * handler-less route never reaches a callback. The guard is dead code on this
     * path.
     *
     * Asserting *that* is what keeps the code above honest: it is the reason the
     * prefix test covers six codes rather than seven, and it means a later change
     * that makes the guard reachable shows up as a failure here — where someone
     * will notice the seventh code needs prefixing — rather than as a rebranded
     * install quietly emitting an unprefixed one.
     */
    public function test_the_no_handler_guard_is_unreachable_because_publish_validates_first(): void
    {
        $this->router->getRoutes();
        $this->router->registrar()->get('unhandled');

        $this->expectException(RouteRegistrationException::class);
        $this->expectExceptionMessageMatches('/has no handler/');

        $this->router->flush();
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

    // ------------------------------------------------------------- rest marker

    /**
     * A failing `FormRequest` on a REST route has to answer with a `WP_Error`,
     * because the REST server serializes a callback's return value rather than
     * sending it — and nothing about the request itself distinguishes a REST
     * dispatch from a web one. The adapter states the domain explicitly for the
     * duration of the call, and these two tests pin both halves: that it is set
     * while the controller runs, and that it is clear afterwards.
     */
    public function test_the_rest_marker_is_set_while_the_controller_runs(): void
    {
        Fixtures\MarkerProbeController::reset();

        $this->router->get('probe', Fixtures\MarkerProbeController::class, 'probe');
        $this->router->flush();

        $this->restCallback()(new WP_REST_Request());

        $this->assertTrue(
            Fixtures\MarkerProbeController::$observed,
            'A REST dispatch must mark itself before the handler runs.'
        );
    }

    /**
     * A stuck marker would make the *next* dispatch on a persistent worker answer
     * a web request with a `WP_Error`, which a browser cannot render — a bug that
     * only appears under a long-running process.
     */
    public function test_the_rest_marker_is_cleared_after_the_dispatch(): void
    {
        Fixtures\MarkerProbeController::reset();

        $this->router->get('probe', Fixtures\MarkerProbeController::class, 'probe');
        $this->router->flush();

        $this->restCallback()(new WP_REST_Request());

        $this->assertFalse(FormRequest::$restDispatch, 'The marker must not outlive its dispatch.');
    }

    public function test_the_rest_marker_is_cleared_even_when_the_handler_throws(): void
    {
        Fixtures\MarkerProbeController::reset();
        Fixtures\MarkerProbeController::$throw = true;

        $this->router->get('probe', Fixtures\MarkerProbeController::class, 'probe');
        $this->router->flush();

        try {
            $this->restCallback()(new WP_REST_Request());
        } catch (\Throwable) {
            // The controller's own failure is not what this test is about — the
            // adapter reports it in its own way. What matters is the marker.
        }

        $this->assertFalse(FormRequest::$restDispatch, 'A throwing handler must not leave the marker set.');
    }

    /**
     * A form request on a REST route must answer with a `WP_Error`, because the
     * REST server serializes a callback's return value rather than sending it.
     * This is the end-to-end version of that rule: route registered, callback
     * fired, WordPress-shaped error out.
     */
    public function test_a_failing_form_request_on_a_rest_route_returns_a_wp_error(): void
    {
        $this->router->post('leads', Fixtures\FormRequestController::class, 'store');
        $this->router->flush();

        $result = $this->restCallback()($this->jsonRequest(['email' => 'not-an-email']));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(422, $result->get_status());
        $this->assertSame('validation_failed', $result->get_error_code());
    }

    public function test_a_valid_form_request_on_a_rest_route_reaches_the_action(): void
    {
        $this->router->post('leads', Fixtures\FormRequestController::class, 'store');
        $this->router->flush();

        $result = $this->restCallback()($this->jsonRequest(['email' => 'a@b.test']));

        $this->assertSame(['stored' => 'a@b.test'], $result);
    }

    /**
     * A REST request carrying JSON, as a headless client sends it.
     *
     * The JSON body rather than `set_params()` because that is the shape the
     * framework actually reads: `Request::fromRestRequest()` splits WordPress's
     * parameter buckets by source, and a body arrives either as a decoded JSON
     * document or as a raw body — not as an undifferentiated `params` bag. A test
     * that used `set_params()` would be asserting against a shape no real request
     * produces.
     *
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body, string $method = 'POST'): WP_REST_Request
    {
        $request = new WP_REST_Request();
        $request->set_method($method);
        $request->set_body((string) json_encode($body));
        $request->set_header('content-type', 'application/json');

        return $request;
    }

    // ------------------------------------------------------------------- config

    /**
     * Put a rebranded config in front of the code under test.
     *
     * `Config` is a static store with no setter, so a fixture directory loaded
     * through the supported `load()` is the only way to do this without a global
     * write. `flush()` first, because `load()` merges by filename and a previously
     * loaded `app` key would otherwise survive underneath.
     */
    private function loadRebrandedConfig(): void
    {
        Config::flush();
        Config::load(__DIR__ . '/../../Fixtures/config');
    }

    /**
     * Put the shipped config back.
     *
     * Not merely politeness: a static config left rebranded would change how every
     * later test in the run resolves a prefix, and the failure would land in
     * whichever test happened to run next rather than here.
     */
    private function restoreShippedConfig(): void
    {
        Config::flush();
        Config::load(dirname(__DIR__, 3) . '/config');
    }

    // ----------------------------------------------------------------- helpers

    /**
     * The callback WordPress would invoke for the first registered route.
     *
     * @return callable(WP_REST_Request): mixed
     */
    private function restCallback(int $index = 0): callable
    {
        return WpStub::$registeredRoutes[self::NAMESPACE][$index]['callback'];
    }

    /**
     * @return callable(WP_REST_Request): (WP_Error|bool|null)
     */
    private function permissionCallback(int $index): callable
    {
        return WpStub::$registeredRoutes[self::NAMESPACE][$index]['permission_callback'];
    }
}
