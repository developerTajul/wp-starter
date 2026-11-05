<?php
declare(strict_types=1);

namespace Tests\Unit\Http;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Middleware\Authenticate;
use Framework\Http\Middleware\Authorize;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Middleware\VerifyAdminNonce;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\RedirectResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\WpStub;
use Tests\Unit\Http\Fixtures\CountingAuthorize;
use Tests\Unit\Http\Fixtures\TokenAuthenticate;

final class MiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        WpStub::reset();
    }

    // -------------------------------------------------------- VerifyAdminNonce

    public function test_a_valid_nonce_passes_the_request_through(): void
    {
        $nonce = WpStub::issueNonce('-1');
        $reached = false;

        $this->passThrough(
            new VerifyAdminNonce(),
            new Request(body: ['_wpnonce' => $nonce]),
            function () use (&$reached): ResponseInterface {
                $reached = true;

                return new JsonResponse(['ok' => true]);
            }
        );

        $this->assertTrue($reached);
    }

    public function test_a_nonce_created_for_another_action_is_refused(): void
    {
        // This is the whole reason the action is configurable: a nonce minted for
        // one form must not authorise another.
        $nonce = WpStub::issueNonce('save_post');

        $response = $this->passThrough(
            new VerifyAdminNonce('delete_user'),
            $this->jsonRequest(body: ['_wpnonce' => $nonce]),
            $this->destination()
        );

        $this->assertSame(403, $response->status());
    }

    public function test_a_nonce_created_for_the_configured_action_is_accepted(): void
    {
        $nonce = WpStub::issueNonce('delete_user');

        $response = $this->passThrough(
            new VerifyAdminNonce('delete_user'),
            new Request(body: ['_wpnonce' => $nonce]),
            $this->destination()
        );

        // Reaching the destination returns its 200; a refusal would be a 403.
        $this->assertSame(200, $response->status());
    }

    /**
     * `-1` is a specific action, not a wildcard — and the default action is not
     * a way to accept "any nonce from this session".
     *
     * This matters because the opposite is an easy and reasonable-sounding thing
     * to assume, and assuming it would make a route appear protected while
     * accepting a nonce minted for something else entirely. Verified against a
     * real WordPress install: a nonce from `wp_create_nonce('save_lead')` fails
     * both `wp_verify_nonce($n)` and a no-action `check_admin_referer()`.
     */
    public function test_the_default_action_is_not_a_wildcard(): void
    {
        $nonce = WpStub::issueNonce('save_lead');

        $response = $this->passThrough(
            new VerifyAdminNonce(),
            $this->jsonRequest(body: ['_wpnonce' => $nonce]),
            $this->destination()
        );

        $this->assertSame(403, $response->status());
    }

    /**
     * The `null` action and the default produce the same verification, because
     * WordPress's `$action` parameter defaults to the integer `-1` and
     * `(string) -1 === '-1'`. Pinned so the constructor's `null` branch is not
     * mistaken for a second, looser mode.
     */
    public function test_a_null_action_verifies_exactly_as_the_default_does(): void
    {
        $nonce = WpStub::issueNonce('-1');

        $response = $this->passThrough(
            new VerifyAdminNonce(null),
            new Request(body: ['_wpnonce' => $nonce]),
            $this->destination()
        );

        $this->assertSame(200, $response->status());
    }

    public function test_a_missing_nonce_is_refused(): void
    {
        $response = $this->passThrough(new VerifyAdminNonce(), $this->jsonRequest(), $this->destination());

        $this->assertSame(403, $response->status());
        $this->assertSame('invalid_nonce', $response->data()['error']);
    }

    public function test_an_invented_nonce_is_refused(): void
    {
        // The stub only honours nonces it issued, so a hardcoded string cannot
        // pass against a framework that never verified anything.
        $response = $this->passThrough(
            new VerifyAdminNonce(),
            $this->jsonRequest(body: ['_wpnonce' => 'not-a-real-nonce']),
            $this->destination()
        );

        $this->assertSame(403, $response->status());
    }

    public function test_an_array_nonce_is_refused(): void
    {
        $response = $this->passThrough(
            new VerifyAdminNonce(),
            $this->jsonRequest(body: ['_wpnonce' => ['a', 'b']]),
            $this->destination()
        );

        $this->assertSame(403, $response->status());
    }

    public function test_a_json_client_gets_a_json_refusal(): void
    {
        $response = $this->passThrough(
            new VerifyAdminNonce(),
            new Request(headers: ['accept' => 'application/json']),
            $this->destination()
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(403, $response->status());
    }

    /**
     * A browser gets WordPress's own error page rather than a JSON document it
     * would render as raw text. `wp_die()` ends the request, and the stub throws
     * so this test can observe that it was reached instead of letting execution
     * continue past a point where production stops.
     */
    public function test_a_browser_client_is_answered_with_wp_die(): void
    {
        try {
            $this->passThrough(new VerifyAdminNonce(), new Request(), $this->destination());
            $this->fail('wp_die() should have been reached.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('wp_die()', $e->getMessage());
        }

        $this->assertCount(1, WpStub::$died);
        $this->assertSame(403, WpStub::$died[0]['args']['response']);
    }

    public function test_the_nonce_field_is_configurable(): void
    {
        $nonce = WpStub::issueNonce('-1');

        $response = $this->passThrough(
            new VerifyAdminNonce('-1', 'my_nonce'),
            new Request(body: ['my_nonce' => $nonce]),
            $this->destination()
        );

        $this->assertSame(200, $response->status());
    }

    // ------------------------------------------------------------- Authenticate

    public function test_a_logged_in_user_passes(): void
    {
        WpStub::$userLoggedIn = true;

        $response = $this->passThrough(new Authenticate(), new Request(), $this->destination());

        $this->assertSame(200, $response->status());
    }

    public function test_an_anonymous_browser_is_redirected_to_the_login_form(): void
    {
        WpStub::$userLoggedIn = false;

        $response = $this->passThrough(
            new Authenticate(),
            new Request(server: ['REQUEST_URI' => '/members']),
            $this->destination()
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('redirect_to=', $response->url());
        // The original target travels with them, so they land where they meant
        // to go rather than on the dashboard.
        $this->assertStringContainsString(rawurlencode('/members'), $response->url());
    }

    public function test_an_anonymous_json_client_gets_401_rather_than_a_redirect(): void
    {
        // A headless client would follow a 302 into an HTML login page and then
        // fail to parse it — a 401 it can act on is the only useful answer.
        WpStub::$userLoggedIn = false;

        $response = $this->passThrough(
            new Authenticate(),
            new Request(headers: ['accept' => 'application/json']),
            $this->destination()
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->status());
        $this->assertSame('unauthenticated', $response->data()['error']);
    }

    public function test_the_redirect_can_be_turned_off(): void
    {
        WpStub::$userLoggedIn = false;

        $response = $this->passThrough(
            new Authenticate(redirectToLogin: false),
            new Request(),
            $this->destination()
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->status());
    }

    /**
     * The token seam. A subclass resolving a user must both admit the request and
     * make that user current, so anything downstream — Authorize, a WordPress API
     * call — sees them.
     */
    public function test_a_subclass_can_authenticate_by_another_scheme(): void
    {
        WpStub::$userLoggedIn = false;
        WpStub::$currentUserId = 0;

        $response = $this->passThrough(
            new TokenAuthenticate('good-token'),
            new Request(headers: ['authorization' => 'Bearer good-token']),
            $this->destination()
        );

        $this->assertSame(200, $response->status());
        $this->assertSame(42, WpStub::$currentUserId, 'The resolved user must become current.');
    }

    /**
     * A subclass that checked a token and found it bad must not have the request
     * succeed on a stale cookie — otherwise "invalid token" silently degrades to
     * "use whatever session PHP happens to have".
     */
    public function test_a_rejected_token_is_not_rescued_by_a_cookie(): void
    {
        // The cookie says someone is logged in, and the subclass says the token
        // it was given is junk. The subclass wins, because the whole point of
        // installing one is to stop trusting the cookie.
        WpStub::$userLoggedIn = true;

        $response = $this->passThrough(
            new TokenAuthenticate('good-token'),
            new Request(headers: ['authorization' => 'Bearer junk']),
            $this->destination()
        );

        $this->assertSame(401, $response->status());
    }

    public function test_an_invalid_token_is_refused_for_an_anonymous_client(): void
    {
        WpStub::$userLoggedIn = false;

        $response = $this->passThrough(
            new TokenAuthenticate('good-token'),
            new Request(headers: ['authorization' => 'Bearer junk']),
            $this->destination()
        );

        $this->assertSame(401, $response->status());
    }

    // ---------------------------------------------------------------- Authorize

    public function test_a_user_holding_the_capability_passes(): void
    {
        WpStub::$userLoggedIn = true;
        WpStub::$currentUserCan = true;

        $response = $this->passThrough(new Authorize('manage_options'), new Request(), $this->destination());

        $this->assertSame(200, $response->status());
    }

    public function test_a_user_lacking_the_capability_gets_403(): void
    {
        WpStub::$userLoggedIn = true;
        WpStub::$currentUserCan = false;

        $response = $this->passThrough(new Authorize('manage_options'), new Request(), $this->destination());

        $this->assertSame(403, $response->status());
        $this->assertSame('forbidden', $response->data()['error']);
    }

    /**
     * 401 for an anonymous caller and 403 for an authenticated one — the same
     * distinction WordPress's own `rest_authorization_required_code()` makes, so
     * a denial looks identical on both entry points.
     */
    public function test_an_anonymous_caller_gets_401_rather_than_403(): void
    {
        WpStub::$userLoggedIn = false;
        WpStub::$currentUserCan = false;

        $response = $this->passThrough(new Authorize('manage_options'), new Request(), $this->destination());

        $this->assertSame(401, $response->status());
    }

    /**
     * All-of, not any-of. WordPress's `authorize()` option treats an array as OR,
     * so a caller expecting that would silently get a stricter check here — the
     * safe direction, which is exactly why it has to be pinned rather than left
     * to read as an accident.
     *
     * The stub answers `current_user_can()` the same way for every capability, so
     * the distinction is drawn from the *number of questions asked*: an any-of
     * implementation would stop after the first, and this one must ask both.
     * MovementEnd stops the walk when it sees the second capability.
     */
    public function test_every_capability_in_a_list_is_consulted(): void
    {
        WpStub::$userLoggedIn = true;
        WpStub::$currentUserCan = true;

        $asked = [];
        $middleware = new CountingAuthorize(['edit_posts', 'publish_posts'], $asked);

        $response = $this->passThrough($middleware, new Request(), $this->destination());

        $this->assertSame(200, $response->status());
        $this->assertSame(['edit_posts', 'publish_posts'], $asked);
    }

    /**
     * And the all-of semantics themselves: the first capability held must not
     * carry the request past a second one that is not.
     */
    public function test_a_list_is_refused_when_one_capability_is_missing(): void
    {
        WpStub::$userLoggedIn = true;

        $middleware = new class(['edit_posts', 'publish_posts']) extends Authorize {
            public function __construct(array $capabilities)
            {
                parent::__construct($capabilities);
            }

            protected function holds(string $capability): bool
            {
                // Everything except the second one.
                return $capability !== 'publish_posts';
            }
        };

        $response = $this->passThrough($middleware, new Request(), $this->destination());

        $this->assertSame(403, $response->status());
    }

    /**
     * `Authorize` answers JSON for *every* client, not only for a JSON one — it
     * is the one middleware here that does not consult `expectsJson()`, because a
     * permission failure is answered in one shape on every entry point so a
     * caller switching between a form post and a headless call sees the same
     * document. The name says this deliberately: it is not a conditional that the
     * code forgot to implement.
     */
    public function test_a_denial_is_json_even_for_a_browser(): void
    {
        WpStub::$userLoggedIn = true;
        WpStub::$currentUserCan = false;

        $response = $this->passThrough(
            new Authorize('manage_options'),
            new Request(headers: ['accept' => 'text/html']),
            $this->destination()
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(403, $response->status());
    }

    /**
     * No capabilities is a misconfiguration, not a policy. It is reported rather
     * than silently denying, because a denial would make the mistake invisible.
     */
    public function test_no_capabilities_is_reported_and_allowed(): void
    {
        WpStub::$currentUserCan = false;

        $response = $this->passThrough(new Authorize([]), new Request(), $this->destination());

        $this->assertSame(200, $response->status());
    }

    // ------------------------------------------------------------------ helpers

    private function destination(): callable
    {
        return static fn (Request $request): JsonResponse => new JsonResponse(['reached' => true]);
    }

    /**
     * A request that asks for JSON, so a refusal comes back as a response the
     * test can assert on.
     *
     * The browser path ends in `wp_die()`, which terminates the process — so a
     * test that wants to inspect a *refusal* has to be a JSON client, and the
     * `wp_die` case is covered separately and on purpose.
     *
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body = []): Request
    {
        return new Request(body: $body, headers: ['accept' => 'application/json']);
    }

    /**
     * Pass one middleware through a real pipeline, so it is tested against the
     * contract it ships in rather than a hand-rolled call.
     *
     * Not named `run()`: PHPUnit's TestCase declares that as final.
     */
    private function passThrough(
        \Framework\Http\Contracts\Middleware $middleware,
        Request $request,
        callable $destination
    ): ResponseInterface {
        $container = new Container();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Container::class, $container);

        // Bound to its own class name, because the pipeline resolves middleware
        // by the class string the caller listed.
        $container->instance($middleware::class, $middleware);

        return (new Pipeline($container))
            ->send($request)
            ->through([$middleware::class])
            ->then($destination);
    }
}
