<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Routing\RouteResolver;
use Framework\Routing\Router;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;
use WP_Error;
use WP_REST_Request;

// PHPUnit autoloads classes, not functions, so the plain-function permission
// callbacks these tests exercise have to be pulled in explicitly.
require_once __DIR__ . '/Fixtures/permission-functions.php';

final class RouterTest extends TestCase
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

    /**
     * WordPress is handed the route as a regex matched against the remainder of
     * the request path, so the plugin's own `{param}` spelling has to survive
     * the translation or the route would never match.
     */
    public function test_it_registers_each_route_with_wordpress_under_the_given_namespace(): void
    {
        $this->router->registrar()->get('posts', Fixtures\PostController::class, 'index');

        $this->router->registerRoutes(self::NAMESPACE);

        $registered = WpStub::$registeredRoutes[self::NAMESPACE];
        $this->assertCount(1, $registered);
        $this->assertSame('posts', $registered[0]['route']);
        $this->assertSame(['GET'], $registered[0]['methods']);
    }

    /**
     * A placeholder must become a capture group, otherwise WordPress could not
     * tell one route from another and the value would never reach the handler.
     */
    public function test_a_placeholder_becomes_a_named_capture_group(): void
    {
        $this->router->registrar()->get('posts/{id}', Fixtures\PostController::class, 'show');

        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertSame('posts/(?P<id>[^/]+)', WpStub::$registeredRoutes[self::NAMESPACE][0]['route']);
    }

    /**
     * A typed segment carries a constraint, which is how a route declares that
     * `{id}` is numeric without the controller having to re-validate it.
     */
    public function test_a_typed_placeholder_keeps_its_constraint(): void
    {
        $this->router->registrar()->get('posts/{id:\d+}', Fixtures\PostController::class, 'show');

        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertSame('posts/(?P<id>\d+)', WpStub::$registeredRoutes[self::NAMESPACE][0]['route']);
    }

    public function test_several_placeholders_in_one_path_are_all_translated(): void
    {
        $this->router->registrar()
            ->get('posts/{postId}/comments/{commentId:\d+}')
            ->handle(static fn (): array => []);

        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertSame(
            'posts/(?P<postId>[^/]+)/comments/(?P<commentId>\d+)',
            WpStub::$registeredRoutes[self::NAMESPACE][0]['route']
        );
    }

    public function test_registration_is_a_no_op_when_wordpress_is_absent(): void
    {
        $this->router->registrar()->get('posts', Fixtures\PostController::class, 'index');

        $this->router->registerRoutes(self::NAMESPACE);

        // The stub is always loaded in this suite, so the meaningful assertion
        // is that registration happened exactly once and did not throw.
        $this->assertCount(1, WpStub::$registeredRoutes[self::NAMESPACE]);
    }

    public function test_it_dispatches_to_a_controller_action(): void
    {
        $this->router->registrar()->get('posts', Fixtures\PostController::class, 'index');
        $this->router->registerRoutes(self::NAMESPACE);

        $response = $this->dispatch('posts');

        $this->assertSame(['action' => 'index'], $response->get_data());
        $this->assertSame(200, $response->get_status());
    }

    /**
     * A route parameter must reach the action, and the declared scalar type must
     * survive the round trip — WordPress hands everything over as a string.
     */
    public function test_a_route_parameter_reaches_the_action(): void
    {
        $this->router->registrar()->get('posts/{id}', Fixtures\PostController::class, 'show');
        $this->router->registerRoutes(self::NAMESPACE);

        $response = $this->dispatch('posts/{id}', ['id' => '42']);

        $this->assertSame(['id' => '42'], $response->get_data());
    }

    /**
     * A scalar-typed parameter is filled from the request, which is the case
     * that proves request data takes precedence over container auto-wiring —
     * `int $id` could never otherwise receive the `id` WordPress matched.
     */
    public function test_a_scalar_parameter_is_filled_from_the_request_not_the_container(): void
    {
        $this->router->registrar()->get('posts/{id}/meta', Fixtures\PostController::class, 'meta');
        $this->router->registerRoutes(self::NAMESPACE);

        $response = $this->dispatch('posts/{id}/meta', ['id' => 7]);

        $this->assertSame(['id' => 7, 'type' => 'int'], $response->get_data());
    }

    public function test_a_closure_handler_is_invoked(): void
    {
        $this->router->registrar()->get('ping')->handle(static fn (): array => ['pong' => true]);
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertSame(['pong' => true], $this->dispatch('ping'));
    }

    public function test_a_controller_action_may_return_any_response_status(): void
    {
        $this->router->registrar()->post('posts', Fixtures\PostController::class, 'store');
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertSame(201, $this->dispatch('posts')->get_status());
    }

    /**
     * Every route is allowed by default. A plugin that ships a public endpoint
     * should not have to opt in to that.
     */
    public function test_a_route_with_no_middleware_is_permitted(): void
    {
        $this->router->registrar()->get('posts', Fixtures\PostController::class, 'index');
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertTrue($this->permission('posts'));
    }

    public function test_a_middleware_can_deny_a_request(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->middleware(Fixtures\DenyMiddleware::class);
        $this->router->registerRoutes(self::NAMESPACE);

        $result = $this->permission('posts');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(Fixtures\DenyMiddleware::STATUS, $result->get_status());
    }

    /**
     * The first denial wins: a later middleware must not be able to overturn an
     * earlier one, or authorization would depend on ordering by accident.
     */
    public function test_the_first_denying_middleware_wins(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->middleware(Fixtures\DenyMiddleware::class, Fixtures\AllowMiddleware::class);
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertInstanceOf(WP_Error::class, $this->permission('posts'));
    }

    public function test_middleware_runs_in_registration_order(): void
    {
        Fixtures\RecordingMiddleware::$ran = [];

        $this->container->bind(Fixtures\RecordingMiddleware::class, static fn (): Fixtures\RecordingMiddleware => new Fixtures\RecordingMiddleware('only'));

        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->middleware(Fixtures\RecordingMiddleware::class);
        $this->router->registerRoutes(self::NAMESPACE);

        $this->permission('posts');

        $this->assertSame(['only'], Fixtures\RecordingMiddleware::$ran);
    }

    /**
     * An explicit permission callback is consulted on top of middleware, since a
     * route that sets one has deliberately opted into WordPress's own mechanism.
     */
    public function test_an_explicit_permission_callback_can_deny(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->permissionCallback('Tests\Unit\Routing\Fixtures\deny_permission');
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertInstanceOf(WP_Error::class, $this->permission('posts'));
    }

    public function test_an_explicit_permission_callback_can_allow_while_middleware_still_runs(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->permissionCallback('Tests\Unit\Routing\Fixtures\allow_permission')
            ->middleware(Fixtures\DenyMiddleware::class);
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertInstanceOf(WP_Error::class, $this->permission('posts'));
    }

    /**
     * Registering the same route twice is a mistake in the routes file, and it
     * is caught when the route is added rather than when WordPress is asked to
     * register an ambiguous path.
     */
    public function test_a_duplicate_route_throws_at_registration(): void
    {
        $this->expectException(\Framework\Routing\Exceptions\RouteRegistrationException::class);

        $registrar = $this->router->registrar();
        $registrar->get('posts', Fixtures\PostController::class, 'index');
        $registrar->get('posts', Fixtures\PostController::class, 'store');
    }

    /**
     * A controller the container cannot build is a deployment error, not a
     * caller error — but it must still be a *response*.
     *
     * This previously let the container's EntryNotFoundException escape the REST
     * callback, which WordPress renders as an opaque 500 naming nothing. The
     * route and the class are the two facts needed to fix it, so both are named.
     */
    public function test_a_controller_that_does_not_exist_returns_a_500_naming_the_route(): void
    {
        $this->router->registrar()->get('posts', 'Tests\Unit\Routing\Fixtures\MissingController', 'index');
        $this->router->registerRoutes(self::NAMESPACE);

        $result = $this->dispatch('posts');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(500, $result->get_status());
        $this->assertStringContainsString('MissingController', $result->get_error_message());
        $this->assertStringContainsString('posts', $result->get_error_message());
    }

    /**
     * A broken route definition must reach the client as a 400 naming the
     * problem, not as an uncaught throwable that WordPress renders as an
     * opaque 500 on every request to that endpoint.
     */
    public function test_a_missing_action_returns_a_400_rather_than_a_500(): void
    {
        $this->router->registrar()->get('posts', Fixtures\PostController::class, 'noSuchAction');
        $this->router->registerRoutes(self::NAMESPACE);

        $result = $this->dispatch('posts');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(400, $result->get_status());
        $this->assertStringContainsString('noSuchAction', $result->get_error_message());
    }

    /**
     * A body parameter must not be able to overwrite the value the URL matched.
     *
     * WordPress ranks a JSON/body parameter above the URL when resolving
     * get_param(), so without reading the URL params first a client could POST
     * `{"id": 999}` to `/posts/123` and have the controller act on 999.
     */
    public function test_a_body_parameter_cannot_override_a_route_placeholder(): void
    {
        $this->router->registrar()->get('posts/{id}', Fixtures\PostController::class, 'show');
        $this->router->registerRoutes(self::NAMESPACE);

        $request = new WP_REST_Request();
        $request->set_url_params(['id' => '123']);
        $request->set_params(['id' => '999']);

        $result = $this->dispatchRequest('posts/{id}', $request);

        $this->assertSame(['id' => '123'], $result->get_data());
    }

    /**
     * A JSON `null` for a scalar parameter used to be passed straight into the
     * action, where PHP raised a TypeError inside the controller.
     */
    public function test_a_null_body_value_does_not_reach_a_scalar_parameter(): void
    {
        $this->router->registrar()->get('posts/{slug}', Fixtures\PostController::class, 'bySlug');
        $this->router->registerRoutes(self::NAMESPACE);

        $request = new WP_REST_Request();
        $request->set_url_params(['slug' => 'hello']);
        $request->set_params(['slug' => null]);

        // The URL value must win, so this must not raise a TypeError.
        $this->assertSame(['slug' => 'hello'], $this->dispatchRequest('posts/{slug}', $request)->get_data());
    }

    /**
     * A value of the wrong type is reported as a 400 that names the action,
     * rather than a TypeError from inside it.
     */
    public function test_a_type_mismatch_is_reported_as_a_400(): void
    {
        $this->router->registrar()->get('posts/{id}/meta', Fixtures\PostController::class, 'meta');
        $this->router->registerRoutes(self::NAMESPACE);

        $request = new WP_REST_Request();
        $request->set_url_params(['id' => ['not', 'a', 'scalar']]);

        $result = $this->dispatchRequest('posts/{id}/meta', $request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(400, $result->get_status());
    }

    /**
     * The string "false" must reach a `bool` parameter as false.
     *
     * A request body of `{"published": "false"}` arrives as the non-empty string
     * "false", and PHP coerces a non-empty string to true — so the controller
     * would publish the record. `accepts()` already whitelists 'true'/'false' as
     * valid bools, so the intent to support them was there; the coercion simply
     * has to happen before reflection, not after.
     *
     * "0" is included because it coerces correctly on its own, which is exactly
     * what makes the bug treacherous: only some false-y spellings invert.
     */
    public function test_the_string_false_reaches_a_bool_parameter_as_false(): void
    {
        $this->router->registrar()->post('posts/publish', Fixtures\PostController::class, 'publish');
        $this->router->registerRoutes(self::NAMESPACE);

        foreach (['false', 'FALSE', '0', ''] as $spelling) {
            $request = new WP_REST_Request();
            $request->set_params(['published' => $spelling, 'qty' => '3']);

            $data = $this->dispatchRequest('posts/publish', $request)->get_data();

            $this->assertFalse(
                $data['published'],
                sprintf('The spelling %s must be false, not true.', var_export($spelling, true))
            );
            $this->assertSame(3, $data['qty']);
        }
    }

    public function test_the_string_true_reaches_a_bool_parameter_as_true(): void
    {
        $this->router->registrar()->post('posts/publish', Fixtures\PostController::class, 'publish');
        $this->router->registerRoutes(self::NAMESPACE);

        foreach (['true', 'TRUE', '1', 1, true] as $spelling) {
            $request = new WP_REST_Request();
            $request->set_params(['published' => $spelling]);

            $data = $this->dispatchRequest('posts/publish', $request)->get_data();

            $this->assertTrue($data['published'], sprintf('The spelling %s must be true.', get_debug_type($spelling)));
        }
    }

    /**
     * A permission callback returning null must deny. WordPress treats null as
     * a denial, and `return $allowed ? true : null;` is the canonical idiom —
     * so allowing it here would silently make a protected route public.
     */
    public function test_a_permission_callback_returning_null_denies_the_request(): void    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->permissionCallback('Tests\Unit\Routing\Fixtures\null_permission');
        $this->router->registerRoutes(self::NAMESPACE);

        $result = $this->permission('posts');

        $this->assertNull($result, 'A null return must be passed through so WordPress denies it.');
    }

    /**
     * A literal dot in a path must match a literal dot. Unescaped, it is a regex
     * wildcard and `file.json` would also match `fileXjson`.
     */
    public function test_regex_metacharacters_in_a_literal_path_are_escaped(): void
    {
        $this->router->registrar()->get('export.json')->handle(static fn (): array => []);
        $this->router->registerRoutes(self::NAMESPACE);

        $route = WpStub::$registeredRoutes[self::NAMESPACE][0]['route'];

        $this->assertSame('export\.json', $route);
    }

    /**
     * A specific route must be matched before a parameterised one that would
     * otherwise swallow it — registration order must not decide this.
     */
    public function test_a_literal_route_is_registered_before_a_parameterised_one(): void
    {
        $registrar = $this->router->registrar();
        $registrar->get('posts/{id}', Fixtures\PostController::class, 'show');
        $registrar->get('posts/latest', Fixtures\PostController::class, 'latest');

        $this->router->registerRoutes(self::NAMESPACE);

        $routes = array_column(WpStub::$registeredRoutes[self::NAMESPACE], 'route');

        $this->assertSame('posts/latest', $routes[0]);
        $this->assertSame('posts/(?P<id>[^/]+)', $routes[1]);
    }

    /**
     * A constraint is a regex, and a regex may contain braces.
     *
     * `{id:[0-9]{1,3}}` is the natural way to bound a numeric id, and a
     * non-greedy match up to the first `}` truncates it to `[0-9]{1,3)\}` —
     * which matches nothing. The route is then dead: WordPress 404s every
     * request and nothing reports a problem at registration.
     */
    public function test_a_quantifier_inside_a_constraint_is_preserved(): void
    {
        $this->router->registrar()->get('posts/{id:[0-9]{1,3}}', Fixtures\PostController::class, 'show');

        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertSame('posts/(?P<id>[0-9]{1,3})', WpStub::$registeredRoutes[self::NAMESPACE][0]['route']);
    }

    public function test_a_bounded_constraint_matches_what_it_should_and_rejects_the_rest(): void
    {
        $this->router->registrar()->get('posts/{id:[0-9]{1,3}}', Fixtures\PostController::class, 'show');
        $this->router->registerRoutes(self::NAMESPACE);

        $pattern = '@^' . WpStub::$registeredRoutes[self::NAMESPACE][0]['route'] . '$@i';

        $this->assertSame(1, preg_match($pattern, 'posts/42'));
        $this->assertSame(0, preg_match($pattern, 'posts/1234'));
        $this->assertSame(0, preg_match($pattern, 'posts/abc'));
    }

    /**
     * A constraint may contain parens, including nested ones.
     *
     * Escaping the literal parts of a path means finding the `(?P<name>...)`
     * groups first, and a constraint's own parens make that ambiguous to any
     * splitter that cannot count them. When the group is missed it is quoted as
     * literal text — `posts/\(\?P\<id\>...\)` — so the route matches nothing and
     * WordPress 404s it silently.
     */
    public function test_a_constraint_containing_a_nested_group_is_not_quoted_as_literal(): void
    {
        $this->router->registrar()->get('posts/{id:((\d+))}', Fixtures\PostController::class, 'show');
        $this->router->registerRoutes(self::NAMESPACE);

        $registered = WpStub::$registeredRoutes[self::NAMESPACE][0]['route'];

        $this->assertSame('posts/(?P<id>((\d+)))', $registered);

        $pattern = '@^' . $registered . '$@i';

        $this->assertSame(1, preg_match($pattern, 'posts/42'));
        $this->assertSame(0, preg_match($pattern, 'posts/abc'));

        // The captured value must be the digits, not the extra group's text.
        preg_match($pattern, 'posts/42', $matches);
        $this->assertSame('42', $matches['id']);
    }

    public function test_a_non_capturing_group_with_a_nested_group_survives(): void
    {
        $this->router->registrar()->get('posts/{id:(?:a(b))}')->handle(static fn (): array => []);
        $this->router->registerRoutes(self::NAMESPACE);

        $registered = WpStub::$registeredRoutes[self::NAMESPACE][0]['route'];

        $this->assertSame('posts/(?P<id>(?:a(b)))', $registered);
        $this->assertSame(1, preg_match('@^' . $registered . '$@i', 'posts/ab'));
        $this->assertSame(0, preg_match('@^' . $registered . '$@i', 'posts/axb'));
    }

    /**
     * A paren inside a character class is literal to PCRE and must not be counted
     * as opening a group, or the depth never returns to zero and the group is
     * treated as unterminated.
     */
    public function test_a_paren_inside_a_character_class_does_not_open_a_group(): void
    {
        $this->router->registrar()->get('posts/{id:[(]x}')->handle(static fn (): array => []);
        $this->router->registerRoutes(self::NAMESPACE);

        $registered = WpStub::$registeredRoutes[self::NAMESPACE][0]['route'];

        $this->assertSame('posts/(?P<id>[(]x)', $registered);
        $this->assertSame(1, preg_match('@^' . $registered . '$@i', 'posts/(x'));
    }

    /**
     * A literal paren in the path that opens no group is escaped, so it matches a
     * paren rather than corrupting the pattern.
     */
    public function test_an_unbalanced_literal_paren_is_escaped(): void
    {
        $this->router->registrar()->get('posts/(draft')->handle(static fn (): array => []);
        $this->router->registerRoutes(self::NAMESPACE);

        $registered = WpStub::$registeredRoutes[self::NAMESPACE][0]['route'];

        $this->assertSame('posts/\(draft', $registered);
        $this->assertSame(1, preg_match('@^' . $registered . '$@i', 'posts/(draft'));
        $this->assertSame(0, preg_match('@^' . $registered . '$@i', 'posts/draft'));
    }

    /**
     * A placeholder in a path that also contains regex metacharacters: only the
     * literal halves are quoted, and both placeholders stay live.
     */
    public function test_only_the_literal_segments_are_quoted_around_placeholders(): void
    {
        $this->router->registrar()->get('v1.0/{id:\d+}/{slug:[a-z-]+}')->handle(static fn (): array => []);
        $this->router->registerRoutes(self::NAMESPACE);

        $registered = WpStub::$registeredRoutes[self::NAMESPACE][0]['route'];

        $this->assertSame('v1\.0/(?P<id>\d+)/(?P<slug>[a-z-]+)', $registered);
        $this->assertSame(1, preg_match('@^' . $registered . '$@i', 'v1.0/7/my-post'));

        // The escaped `.` must not match an arbitrary character.
        $this->assertSame(0, preg_match('@^' . $registered . '$@i', 'v1X0/7/my-post'));
    }

    /**
     * A `{` that is not part of a placeholder must be treated as a literal, not
     * swallowed as a malformed capture group.
     */
    public function test_a_brace_that_is_not_a_placeholder_is_escaped_literally(): void
    {
        $this->router->registrar()->get('weird/{not a name}')->handle(static fn (): array => []);
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertSame(
            'weird/\{not a name\}',
            WpStub::$registeredRoutes[self::NAMESPACE][0]['route']
        );
    }

    /**
     * An empty constraint must fall back to the default, not compile to a group
     * that matches nothing.
     *
     * `{id:}` splits to an empty string rather than null, so a `?? '[^/]+'`
     * fallback misses it and the group becomes `(?P<id>)` — which matches the
     * empty string instead of a path segment. WordPress anchors every pattern, so
     * `posts/{id:}` would match `posts/` and shadow a sibling route registered
     * after it.
     */
    public function test_an_empty_constraint_falls_back_to_the_default(): void
    {
        $this->router->registrar()->get('posts/{id:}')->handle(static fn (): array => []);
        $this->router->registerRoutes(self::NAMESPACE);

        $registered = WpStub::$registeredRoutes[self::NAMESPACE][0]['route'];

        $this->assertSame('posts/(?P<id>[^/]+)', $registered);

        // And it must not match a path with no segment there.
        $this->assertSame(0, preg_match('@^' . $registered . '$@i', 'posts/'));
        $this->assertSame(1, preg_match('@^' . $registered . '$@i', 'posts/42'));
    }

    /**
     * A whitespace-only constraint is as malformed as an empty one.
     */
    public function test_a_whitespace_only_constraint_falls_back_to_the_default(): void
    {
        $this->router->registrar()->get('posts/{id:   }')->handle(static fn (): array => []);
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertSame('posts/(?P<id>[^/]+)', WpStub::$registeredRoutes[self::NAMESPACE][0]['route']);
    }

    /**
     * WordPress calls the permission callback with no error handling of its own,
     * so a callback that cannot be resolved would be an unhandled fatal on a
     * route any anonymous client can reach. It must fail closed instead.
     */
    public function test_an_unresolvable_permission_callback_denies_rather_than_fatals(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->permissionCallback('thisFunctionDoesNotExist');

        $this->router->registerRoutes(self::NAMESPACE);

        $result = $this->permission('posts');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('wp_mini_laravel_permission_callback_failed', $result->get_error_code());
        $this->assertStringContainsString('thisFunctionDoesNotExist', $result->get_error_message());
    }

    /**
     * A bare method name is not a resolvable callback, and must deny rather than
     * be mistaken for a working one.
     *
     * Nothing ties `canDoIt` to the route's controller, so the container reflects
     * it as a plain function, does not find one, and throws. Documenting this as a
     * supported form would be worse than the throw: the operator would write a
     * callback that silently 401s every request behind what looks like a real
     * authorization failure.
     */
    public function test_a_bare_method_name_is_not_a_resolvable_permission_callback(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->permissionCallback('canDoIt');

        $this->router->registerRoutes(self::NAMESPACE);

        $result = $this->permission('posts');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('wp_mini_laravel_permission_callback_failed', $result->get_error_code());

        // The message names the callback and carries the reason, so a wiring bug
        // is distinguishable from a route that genuinely denies this user.
        $this->assertStringContainsString('canDoIt', $result->get_error_message());
        $this->assertStringContainsString('does not exist', $result->get_error_message());
    }

    /**
     * The form that *is* supported for "a method on a class": a plain function
     * name, a static method, and an instance method on a resolvable class. Pinned
     * together because the docblocks describe them as one contract.
     */
    public function test_a_named_function_permission_callback_resolves(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->permissionCallback('Tests\Unit\Routing\Fixtures\allow_permission');

        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertTrue($this->permission('posts'));
    }

    /**
     * One bad route must not take the others down with it.
     *
     * Validating inside the registration loop meant WordPress had already
     * accepted the earlier routes when the exception fired, so the rest were
     * never handed over — and the namespace was already flagged, making a retry
     * a silent no-op.
     */
    public function test_a_route_with_no_handler_does_not_leave_the_others_registered(): void
    {
        $registrar = $this->router->registrar();
        $registrar->get('ok', Fixtures\PostController::class, 'index');
        $registrar->get('bad');  // no handler: throws at registration
        $registrar->get('later', Fixtures\PostController::class, 'index');

        try {
            $this->router->registerRoutes(self::NAMESPACE);
            $this->fail('Expected a RouteRegistrationException.');
        } catch (\Framework\Routing\Exceptions\RouteRegistrationException $e) {
            // expected
        }

        // Nothing was handed to WordPress, so a fixed routes file can retry and
        // register all three — rather than colliding with the ones that landed.
        $this->assertSame([], WpStub::$registeredRoutes[self::NAMESPACE] ?? []);
    }

    public function test_registration_can_be_retried_after_a_bad_route_is_fixed(): void
    {
        $registrar = $this->router->registrar();
        $registrar->get('bad');

        try {
            $this->router->registerRoutes(self::NAMESPACE);
        } catch (\Framework\Routing\Exceptions\RouteRegistrationException $e) {
            // expected
        }

        // Fix the offending route by removing it, then retry.
        $fixed = new Router($this->container, new RouteResolver($this->container));
        $fixed->registrar()->get('good', Fixtures\PostController::class, 'index');
        $fixed->registerRoutes(self::NAMESPACE);

        $this->assertCount(1, WpStub::$registeredRoutes[self::NAMESPACE]);
    }

    /**
     * WordPress can fire rest_api_init more than once in a process. A second
     * pass must be a no-op rather than colliding with the first and throwing.
     */
    public function test_registering_twice_is_a_no_op(): void
    {
        $this->router->registrar()->get('posts', Fixtures\PostController::class, 'index');

        $this->router->registerRoutes(self::NAMESPACE);
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertCount(1, WpStub::$registeredRoutes[self::NAMESPACE]);
    }

    /**
     * Dispatch an already-built request without rebuilding the parameter bag.
     */
    private function dispatchRequest(string $route, WP_REST_Request $request): mixed
    {
        foreach (WpStub::$registeredRoutes[self::NAMESPACE] as $registration) {
            if ($registration['route'] === $this->expectedPattern($route)) {
                return ($registration['callback'])($request);
            }
        }

        $this->fail(sprintf('No route was registered for [%s].', $route));
    }

    /**
     * Invoke the callback WordPress would call, for the route with this path.
     */
    private function dispatch(string $route, array $params = []): mixed
    {
        $request = new WP_REST_Request();
        // Route placeholders arrive as URL params, which is the source the
        // resolver reads first; body and query params are set the same way in
        // the tests that specifically exercise them.
        $request->set_url_params($params);
        $request->set_params($params);

        foreach (WpStub::$registeredRoutes[self::NAMESPACE] as $registration) {
            if ($registration['route'] === $this->expectedPattern($route)) {
                return ($registration['callback'])($request);
            }
        }

        $this->fail(sprintf('No route was registered for [%s].', $route));
    }

    private function permission(string $route): mixed
    {
        $request = new WP_REST_Request();

        foreach (WpStub::$registeredRoutes[self::NAMESPACE] as $registration) {
            if ($registration['route'] === $this->expectedPattern($route)) {
                return ($registration['permission_callback'])($request);
            }
        }

        $this->fail(sprintf('No route was registered for [%s].', $route));
    }

    /**
     * The pattern the router should have produced for a route, so tests look
     * routes up by the path a routes file writes.
     *
     * Delegates to the router's own translation rather than reimplementing it:
     * a second copy would drift, and these tests would then pass while the
     * router did something else.
     */
    private function expectedPattern(string $route): string
    {
        return Router::toWordPressRoute($route);
    }
}
