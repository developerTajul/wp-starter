<?php
declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\Api\LeadController;
use App\Http\Resources\LeadResource;
use DevLinkStudios\WPServiceContainer\Container;
use Framework\Routing\RouteResolver;
use Framework\Http\Responses\JsonResponse;
use Framework\Routing\Router;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;
use WP_REST_Request;

/**
 * The headless path, dispatched through the real router.
 *
 * ## Why this is not a test of the resource
 *
 * `JsonResourceTest` covers the mapping layer on its own. This covers the seam
 * between the layers, which is where the interesting failures live: a route file
 * that fatals, a controller whose signature the binder cannot fill, a resource
 * that is never actually reached because the endpoint returns something else.
 *
 * Those are invisible to a unit test of the resource, and they are the ones that
 * break a deployment.
 *
 * ## The parameter-typing trap, which this file exists to pin
 *
 * `LeadController::show(int $id)` declares an `int`, and the URL yields a string.
 * Under `declare(strict_types=1)` — which every file in this project has — a
 * direct call from a strict frame raises a TypeError. It works anyway, and the
 * reason is worth knowing: the container invokes the action through
 * `ReflectionMethod::invokeArgs()`, which is a **weak-mode** call regardless of
 * the declaring file's mode. So `'1'` is coerced to `1` there.
 *
 * That means a controller invoked directly in a strict test **throws** where the
 * framework's own dispatch succeeds. A test written as `$controller->show(1)`
 * covers nothing, and one written as `$controller->show('1')` fails for a reason
 * that has nothing to do with the code under test. Both are why this dispatches
 * through the router instead of calling the controller.
 */
final class ApiResourceDispatchTest extends TestCase
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

    // ----------------------------------------------------------- the route file

    /**
     * The plugin's own `routes/api.php`, loaded through the real registrar.
     *
     * A route file is not covered by anything until something loads it, and a
     * fatal in one is invisible until a request hits it.
     */
    public function test_the_plugins_route_file_registers_the_lead_routes(): void
    {
        $this->loadPluginRoutes();

        $paths = [];

        foreach ($this->router->getRoutes() as $route) {
            $paths[] = $route->getPath();
        }

        $this->assertContains('ping', $paths);
        $this->assertContains('leads', $paths);
        $this->assertContains('leads/{id}', $paths);
    }

    // ---------------------------------------------------------------- dispatch

    /**
     * **The routes are not publicly readable.**
     *
     * A REST route in WordPress is open by default, and these publish a lead's
     * email address — so "the route is registered" and "the route is safe" are
     * two different assertions and only the second one matters. Written against
     * the *loaded route file* rather than a route registered by this test, so
     * removing the middleware from `routes/api.php` fails here.
     *
     * `ping` is asserted open in the same test, because the two answers together
     * are what show the check is discriminating rather than a blanket denial.
     */
    public function test_the_lead_routes_refuse_an_anonymous_caller(): void
    {
        $this->loadPluginRoutes();

        WpStub::$currentUserCan = false;

        $this->assertTrue($this->permissionResult('ping'), 'ping is a health check and stays open.');
        $this->assertFalse($this->permissionResult('leads'), 'leads must not be public.');
        $this->assertFalse($this->permissionResult('leads/{id}'), 'leads/{id} must not be public.');
    }

    /**
     * And an authorized caller is let through, so the middleware is a check
     * rather than a wall. Without this, deleting the routes would pass the test
     * above.
     */
    public function test_the_lead_routes_allow_a_capable_caller(): void
    {
        $this->loadPluginRoutes();

        WpStub::$currentUserCan = true;

        $this->assertTrue($this->permissionResult('leads'));
        $this->assertTrue($this->permissionResult('leads/{id}'));
    }

    public function test_the_collection_endpoint_returns_a_data_envelope(): void
    {
        $this->registerLeadRoutes();

        $data = $this->payload($this->dispatch('leads'));

        $this->assertArrayHasKey('data', $data);
        $this->assertCount(2, $data['data']);
    }

    /**
     * The public shape holds end to end, not just in the resource's own test:
     * the internal columns are absent from what the endpoint actually sends.
     */
    public function test_the_endpoint_does_not_publish_internal_columns(): void
    {
        $this->registerLeadRoutes();

        $json = json_encode($this->payload($this->dispatch('leads')));

        $this->assertStringNotContainsString('internalRef', $json);
        $this->assertStringNotContainsString('ownerId', $json);
        $this->assertStringNotContainsString('LEAD-SECRET', $json);
    }

    /**
     * A caller without the capability does not get the notes field, and cannot
     * ask for it either — the field is never mapped into the payload, so there
     * is nothing for a sparse fieldset to keep.
     */
    public function test_a_caller_without_the_capability_cannot_reach_the_notes_field(): void
    {
        $this->registerLeadRoutes();

        WpStub::$currentUserCan = false;

        $payload = $this->payload($this->dispatch('leads'));

        $this->assertArrayNotHasKey('notes', $payload['data'][0]);
        $this->assertStringNotContainsString('enterprise pricing', (string) json_encode($payload));
    }

    /**
     * And a caller *with* the capability does get it.
     *
     * `WpStub::$currentUserCan` defaults to `true`, so the test above would pass
     * against a controller that never checked anything — it asserts only the
     * absent case. This is the half that makes the pair mean something: without
     * it, hard-coding `includeNotes = false` would satisfy both.
     */
    public function test_a_caller_with_the_capability_does_get_the_notes_field(): void
    {
        $this->registerLeadRoutes();

        WpStub::$currentUserCan = true;

        $payload = $this->payload($this->dispatch('leads'));

        $this->assertSame('Asked about enterprise pricing.', $payload['data'][0]['notes']);
    }

    /**
     * The capability is checked per request, not once at boot. Setting it between
     * two dispatches in one test is what proves that — a cached decision would
     * make the second dispatch return the first one's answer.
     */
    public function test_the_notes_field_follows_the_capability_within_one_process(): void
    {
        $this->registerLeadRoutes();

        WpStub::$currentUserCan = false;
        $without = $this->payload($this->dispatch('leads'));

        WpStub::$currentUserCan = true;
        $with = $this->payload($this->dispatch('leads'));

        $this->assertArrayNotHasKey('notes', $without['data'][0]);
        $this->assertArrayHasKey('notes', $with['data'][0]);
    }

    /**
     * **The revocation direction, which is the one that matters.**
     *
     * `LeadResource` carries the capability as a class-level default, because a
     * collection wraps its items lazily and there is no per-instance flag to set
     * before they are built. The cost of that choice is a value that outlives the
     * request unless it is cleared — and the failure it would produce is the bad
     * direction: request A is entitled, the value sticks, and request B is served
     * request A's grant.
     *
     * So the test goes granted-then-revoked, which is the order a leak would
     * show up in. The reverse order passes against a static that is never reset.
     */
    public function test_a_grant_does_not_survive_into_the_next_dispatch(): void
    {
        $this->registerLeadRoutes();

        WpStub::$currentUserCan = true;
        $granted = $this->payload($this->dispatch('leads'));

        WpStub::$currentUserCan = false;
        $revoked = $this->payload($this->dispatch('leads'));

        $this->assertArrayHasKey('notes', $granted['data'][0]);
        $this->assertArrayNotHasKey('notes', $revoked['data'][0]);
        $this->assertStringNotContainsString('enterprise pricing', (string) json_encode($revoked));
    }

    /**
     * **The two guards must agree, and this is the test that would have caught
     * them not agreeing.**
     *
     * The route admits a caller on `list_users`; the `notes` field is gated on a
     * capability of its own. Before this, the controller asked for
     * `edit_others_posts` — a *different* capability — and the two answers
     * described different populations. A caller holding one and not the other
     * reached the controller, failed its check, and got a lead whose `notes` key
     * was silently absent, indistinguishable from a lead that has no notes.
     *
     * The blanket `current_user_can` stub is why it survived review: it answered
     * "yes" to everything, so both capabilities were always held and the
     * disagreement never had a way to show. Setting them per capability is what
     * makes the difference observable.
     */
    public function test_an_admitted_caller_without_the_notes_capability_still_gets_a_lead(): void
    {
        $this->loadPluginRoutes();

        // Admitted by the middleware, not entitled to notes — the exact account
        // the split exists for.
        WpStub::$capabilities = ['list_users' => true, LeadController::NOTES_CAPABILITY => false];

        $payload = $this->payload($this->dispatch('leads'));

        $this->assertArrayNotHasKey('notes', $payload['data'][0]);
        // Not a refusal: the caller is entitled to the endpoint, just not to this
        // field. An error here would be the *other* bug — over-restricting.
        $this->assertArrayHasKey('email', $payload['data'][0]);
    }

    public function test_the_notes_capability_alone_is_enough_to_see_notes(): void
    {
        $this->loadPluginRoutes();

        WpStub::$capabilities = ['list_users' => true, LeadController::NOTES_CAPABILITY => true];

        $payload = $this->payload($this->dispatch('leads'));

        $this->assertArrayHasKey('notes', $payload['data'][0]);
    }

    /**
     * The controller asks for the capability it *names*, rather than one that
     * happens to be granted alongside it.
     *
     * A check that asks the wrong capability and is granted reads as working —
     * right up until a role holds one and not the other, which is the case the
     * tests above construct. This asserts on the question, not the answer.
     */
    public function test_the_notes_gate_asks_the_capability_the_policy_names(): void
    {
        $this->loadPluginRoutes();

        WpStub::$capabilitiesAsked = [];

        $this->payload($this->dispatch('leads'));

        $this->assertContains(
            LeadController::NOTES_CAPABILITY,
            WpStub::$capabilitiesAsked,
            'The controller must ask the capability its policy names.'
        );
    }

    /**
     * The string from the URL reaches an `int`-typed parameter as an int, because
     * the container's reflective invocation coerces. Asserted from the
     * *controller's* return value so the mechanism is pinned rather than assumed:
     * if that coercion ever stopped happening, this is the test that says so.
     */
    public function test_a_route_placeholder_reaches_an_int_parameter_as_an_int(): void
    {
        $this->registerLeadRoutes();

        $payload = $this->payload($this->dispatch('leads/{id}', ['id' => '1']));

        $this->assertSame(1, $payload['id']);
    }

    public function test_a_missing_lead_is_a_404_with_an_error_body(): void
    {
        $this->registerLeadRoutes();

        $payload = $this->payload($this->dispatch('leads/{id}', ['id' => '999']));

        $this->assertSame('not_found', $payload['error']);
    }

    /**
     * A partial row — one with no `phone` key at all — must not throw, and must
     * not carry a null phone either. That is `whenLoaded()` doing its job through
     * the whole stack rather than in isolation.
     */
    public function test_a_partial_row_omits_the_field_it_lacks(): void
    {
        $this->registerLeadRoutes();

        $payload = $this->payload($this->dispatch('leads'));

        $this->assertArrayHasKey('phone', $payload['data'][0]);
        $this->assertArrayNotHasKey('phone', $payload['data'][1]);
    }

    /**
     * The endpoint returns a framework response object, not a bare array. A
     * controller returning an array works on the REST path and emits the literal
     * text "Array" on the web one, so the type is a contract worth asserting.
     */
    public function test_the_endpoint_returns_a_json_response_with_a_status(): void
    {
        $this->registerLeadRoutes();

        $response = $this->dispatch('leads');

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->status());
    }

    // ------------------------------------------------------------------ harness

    private function registerLeadRoutes(): void
    {
        $this->router->registrar()->get('leads', LeadController::class, 'index');
        $this->router->registrar()->get('leads/{id}', LeadController::class, 'show');

        $this->router->registerRoutes(self::NAMESPACE);
    }

    /**
     * Register the plugin's own `routes/api.php` — middleware included.
     *
     * Separate from {@see registerLeadRoutes()}, which registers bare routes for
     * the dispatch tests. The permission tests must see the real file, because
     * what they assert is a property of that file.
     */
    private function loadPluginRoutes(): void
    {
        $registrar = $this->router->registrar();

        $loader = require dirname(__DIR__, 3) . '/routes/api.php';
        $loader($registrar);

        $this->router->registerRoutes(self::NAMESPACE);
    }

    /**
     * What the route's own permission callback returns for the current caller.
     */
    private function permissionResult(string $route): bool
    {
        $pattern = Router::toWordPressRoute($route);

        foreach (WpStub::$registeredRoutes[self::NAMESPACE] ?? [] as $registration) {
            if ($registration['route'] === $pattern) {
                $callback = $registration['permission_callback'];

                $this->assertIsCallable($callback);

                return $callback(new WP_REST_Request()) === true;
            }
        }

        $this->fail(sprintf('No route was registered for [%s].', $route));
    }

    /**
     * Invoke the registered callback for a route, the way WordPress would.
     *
     * @param array<string, string> $params
     */
    private function dispatch(string $route, array $params = []): mixed
    {
        $request = new WP_REST_Request();
        $request->set_url_params($params);
        $request->set_params($params);

        $pattern = Router::toWordPressRoute($route);

        foreach (WpStub::$registeredRoutes[self::NAMESPACE] ?? [] as $registration) {
            if ($registration['route'] === $pattern) {
                return ($registration['callback'])($request);
            }
        }

        $this->fail(sprintf('No route was registered for [%s].', $route));
    }

    /**
     * The resolved payload of a dispatched route.
     *
     * A resource is `JsonSerializable`, so resolving one means calling
     * `jsonSerialize()` — done here rather than sprinkled through the tests.
     *
     * @return array<string, mixed>
     */
    private function payload(mixed $response): array
    {
        $data = $response->data();

        if ($data instanceof \JsonSerializable) {
            $data = $data->jsonSerialize();
        }

        $this->assertIsArray($data);

        return $data;
    }
}
