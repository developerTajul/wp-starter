<?php
declare(strict_types=1);

namespace Tests\Integration\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Middleware\MiddlewareAliases;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

/**
 * The parameterized guards, driven through a real pipeline from a string entry.
 *
 * ## Why this is an integration test and not three unit tests
 *
 * `CheckCapability::handle()` can be called directly with an array of strings,
 * and that would cover the capability logic. It would cover **nothing about the
 * feature**: the whole point of `'capability:manage_options'` is that a colon
 * in a routes file becomes an argument to `handle()`, through the pipeline's
 * split, the alias registry, and the container's variadic spreading. Any of
 * those three could be wrong while a direct call still passed.
 *
 * So every test here starts from the string a routes file would contain —
 * `'capability:list_users'` — and asserts the guard's *observable* effect
 * downstream. That is the seam that was actually being built.
 *
 * ## Why the alias is exercised rather than the class name
 *
 * `'capability:...'` (the alias) is what `routes/web.php` writes, so that is what
 * is tested. The class-name form goes through a strictly smaller path — no
 * registry — and is covered by {@see PipelineTest} directly.
 */
final class ParameterizedMiddlewareTest extends TestCase
{
    private Container $container;
    private MiddlewareAliases $aliases;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->container = new Container();
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Container::class, $this->container);

        $this->aliases = new MiddlewareAliases();
    }

    // ------------------------------------------------------- CheckCapability

    public function test_a_user_holding_the_capability_passes(): void
    {
        WpStub::$userLoggedIn = true;
        WpStub::$currentUserCan = true;

        $reached = false;

        $this->dispatchThrough(
            ['capability:list_users'],
            new Request(),
            function () use (&$reached): ResponseInterface {
                $reached = true;

                return new JsonResponse(['ok' => true]);
            }
        );

        $this->assertTrue($reached);
    }

    public function test_a_user_lacking_the_capability_gets_403(): void
    {
        WpStub::$userLoggedIn = true;
        WpStub::$currentUserCan = false;

        $response = $this->dispatchThrough(
            ['capability:list_users'],
            $this->jsonRequest(),
            $this->destination()
        );

        $this->assertSame(403, $response->status());
    }

    /**
     * The assertion that proves the parameter *arrived*, rather than proving that
     * some capability was checked. A middleware that ignored its parameter would
     * have to pick a capability from somewhere — and the only honest place is the
     * one the route named.
     */
    public function test_the_capability_named_by_the_route_is_the_one_asked_about(): void
    {
        WpStub::$userLoggedIn = true;

        $this->dispatchThrough(['capability:list_users'], $this->jsonRequest(), $this->destination());

        $this->assertSame(['list_users'], WpStub::$capabilitiesAsked);
    }

    public function test_a_different_parameter_asks_about_a_different_capability(): void
    {
        WpStub::$userLoggedIn = true;

        $this->dispatchThrough(['capability:manage_options'], $this->jsonRequest(), $this->destination());

        $this->assertSame(['manage_options'], WpStub::$capabilitiesAsked);
    }

    /**
     * A user holding one capability and not another is the only configuration
     * that can catch a guard asking the wrong capability and being granted by
     * accident — a blanket answer makes the two agree by construction.
     */
    public function test_the_grant_follows_the_named_capability_not_a_blanket_answer(): void
    {
        WpStub::$userLoggedIn = true;
        WpStub::$capabilities = ['edit_posts' => true, 'list_users' => false];

        $refused = $this->dispatchThrough(['capability:list_users'], $this->jsonRequest(), $this->destination());
        $allowed = $this->dispatchThrough(['capability:edit_posts'], $this->jsonRequest(), $this->destination());

        $this->assertSame(403, $refused->status());
        $this->assertSame(200, $allowed->status());
    }

    /**
     * Several capabilities on one entry, all of which must be held — the
     * all-of semantics that mirror {@see \Framework\Http\Middleware\Authorize}
     * and are the *opposite* of WordPress's own any-of array handling.
     */
    public function test_every_capability_in_a_comma_separated_parameter_is_required(): void
    {
        WpStub::$userLoggedIn = true;
        WpStub::$capabilities = ['edit_posts' => true, 'publish_posts' => false];

        $response = $this->dispatchThrough(
            ['capability:edit_posts,publish_posts'],
            $this->jsonRequest(),
            $this->destination()
        );

        $this->assertSame(403, $response->status());
        $this->assertSame(['edit_posts', 'publish_posts'], WpStub::$capabilitiesAsked);
    }

    public function test_a_capability_parameter_with_no_capability_is_refused(): void
    {
        WpStub::$userLoggedIn = true;
        WpStub::$currentUserCan = true;

        // `'capability:'` — the routes-file typo. A guard that defaulted to "no
        // capability required" here would open the route to everyone.
        $response = $this->dispatchThrough(['capability:'], $this->jsonRequest(), $this->destination());

        $this->assertSame(403, $response->status());
        $this->assertSame([], WpStub::$capabilitiesAsked, 'No capability should have been asked about.');
    }

    /**
     * Failing closed when WordPress's function is absent. A capability check that
     * cannot run has authorized nothing.
     */
    public function test_a_missing_current_user_can_denies(): void
    {
        WpStub::$userLoggedIn = true;

        // The stub is process-wide, so the absence is simulated by removing the
        // capability answer rather than the function: `current_user_can` returns
        // false, which is what an absent implementation must also mean.
        WpStub::$currentUserCan = false;

        $response = $this->dispatchThrough(['capability:list_users'], $this->jsonRequest(), $this->destination());

        $this->assertSame(403, $response->status());
    }

    // ------------------------------------------------------------ VerifyNonce

    public function test_a_nonce_minted_for_the_named_action_passes(): void
    {
        $nonce = WpStub::issueNonce('leads_store');
        $reached = false;

        $this->dispatchThrough(
            ['nonce:leads_store'],
            new Request(body: ['_wpnonce' => $nonce]),
            function () use (&$reached): ResponseInterface {
                $reached = true;

                return new JsonResponse(['ok' => true]);
            }
        );

        $this->assertTrue($reached);
    }

    /**
     * The silent failure the parameter exists to prevent: a nonce minted for one
     * action must not authorise another. This is also what proves the action
     * reached the middleware — a guard ignoring its parameter could not tell
     * these two requests apart.
     */
    public function test_a_nonce_minted_for_another_action_is_refused(): void
    {
        $nonce = WpStub::issueNonce('save_post');

        $response = $this->dispatchThrough(
            ['nonce:leads_store'],
            $this->jsonRequest(['_wpnonce' => $nonce]),
            $this->destination()
        );

        $this->assertSame(403, $response->status());
    }

    public function test_a_missing_nonce_is_refused(): void
    {
        $response = $this->dispatchThrough(['nonce:leads_store'], $this->jsonRequest(), $this->destination());

        $this->assertSame(403, $response->status());
    }

    /**
     * `nonce:` with nothing after it is a typo, and unlike `VerifyAdminNonce`'s
     * documented `'-1'` default it must not quietly become one — `-1` is a
     * specific action in WordPress, so defaulting would either accept a nonce
     * minted for something else or reject every real submission, with nothing to
     * say which.
     */
    public function test_a_nonce_parameter_with_no_action_is_refused(): void
    {
        $nonce = WpStub::issueNonce('-1');

        $response = $this->dispatchThrough(
            ['nonce:'],
            $this->jsonRequest(['_wpnonce' => $nonce]),
            $this->destination()
        );

        $this->assertSame(403, $response->status());
    }

    /**
     * The optional second parameter names the request field, for a form that does
     * not use `wp_nonce_field()`'s default.
     */
    public function test_the_nonce_field_is_configurable(): void
    {
        $nonce = WpStub::issueNonce('leads_store');
        $reached = false;

        $this->dispatchThrough(
            ['nonce:leads_store,my_nonce'],
            new Request(body: ['my_nonce' => $nonce, '_wpnonce' => 'invented']),
            function () use (&$reached): ResponseInterface {
                $reached = true;

                return new JsonResponse(['ok' => true]);
            }
        );

        $this->assertTrue(
            $reached,
            'The configured field name was not read — the default _wpnonce was consulted instead.'
        );
    }

    /**
     * A nonce in the default field is still found when the field parameter is
     * omitted, which is what makes the common case free.
     */
    public function test_the_default_field_is_used_when_only_an_action_is_given(): void
    {
        $nonce = WpStub::issueNonce('leads_store');
        $reached = false;

        $this->dispatchThrough(
            ['nonce:leads_store'],
            new Request(body: ['_wpnonce' => $nonce]),
            function () use (&$reached): ResponseInterface {
                $reached = true;

                return new JsonResponse(['ok' => true]);
            }
        );

        $this->assertTrue($reached);
    }

    /**
     * An array where a nonce is expected is refused rather than handed to
     * `wp_verify_nonce()` to make of what it can.
     */
    public function test_an_array_nonce_is_refused(): void
    {
        $response = $this->dispatchThrough(
            ['nonce:leads_store'],
            $this->jsonRequest(['_wpnonce' => ['a', 'b']]),
            $this->destination()
        );

        $this->assertSame(403, $response->status());
    }

    // ------------------------------------------------------------- both, stacked

    /**
     * The two together, in the order `routes/web.php` declares them, each reading
     * its own parameter — which is the property a single shared argument bag
     * would break.
     */
    public function test_both_guards_are_configured_independently(): void
    {
        WpStub::$userLoggedIn = true;
        WpStub::$capabilities = ['list_users' => true];

        $nonce = WpStub::issueNonce('leads_store');
        $reached = false;

        $this->dispatchThrough(
            ['capability:list_users', 'nonce:leads_store'],
            new Request(body: ['_wpnonce' => $nonce]),
            function () use (&$reached): ResponseInterface {
                $reached = true;

                return new JsonResponse(['ok' => true]);
            }
        );

        $this->assertTrue($reached);
        $this->assertSame(['list_users'], WpStub::$capabilitiesAsked);
    }

    /**
     * A nonce minted for the *capability* string — the mistake a shared argument
     * bag would produce, where both layers see the first layer's parameter.
     */
    public function test_the_nonce_action_is_not_the_capability(): void
    {
        WpStub::$userLoggedIn = true;
        WpStub::$capabilities = ['list_users' => true];

        $wrong = WpStub::issueNonce('list_users');

        $response = $this->dispatchThrough(
            ['capability:list_users', 'nonce:leads_store'],
            $this->jsonRequest(['_wpnonce' => $wrong]),
            $this->destination()
        );

        $this->assertSame(403, $response->status());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Drive a stack of string entries through a real pipeline.
     *
     * A fresh pipeline per call, because a pipeline is per-use — reusing one
     * would re-run the previous stack from the top.
     *
     * @param list<string> $stack Route-style entries, e.g. `'capability:list_users'`.
     */
    private function dispatchThrough(array $stack, Request $request, callable $destination): ResponseInterface
    {
        return (new Pipeline($this->container, $this->aliases))
            ->send($request)
            ->through($stack)
            ->then($destination);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body = []): Request
    {
        return new Request(body: $body, headers: ['accept' => 'application/json']);
    }

    /**
     * @return callable(Request): ResponseInterface
     */
    private function destination(): callable
    {
        return static fn (Request $request): ResponseInterface => new JsonResponse(['ok' => true]);
    }
}
