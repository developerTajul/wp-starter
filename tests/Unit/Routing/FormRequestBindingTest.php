<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use Framework\Http\Request;
use Framework\Http\Requests\FormRequest;
use Framework\Http\Responses\JsonResponse;
use Framework\Http\Responses\RedirectResponse;
use Framework\Routing\Exceptions\RouteResolutionException;
use Framework\Routing\ParameterBinder;
use Framework\Routing\Sources\HttpParameterSource;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;
use Tests\Unit\Routing\Fixtures\BinderStoreRequest;

/**
 * The binder's half of the FormRequest contract.
 *
 * Everything here is about *one* property: when validation fails, the action is
 * not called. A controller that ran anyway and read `input('email')` would have
 * the raw, unvalidated value — which is exactly the failure the whole feature
 * exists to prevent, and exactly the failure that would be invisible in a test
 * that only asserted on the response.
 *
 * The counter on the fixture controller is the assertion that matters: every
 * failure case below checks `$controller->calls === 0`, not just the response
 * type.
 */
final class FormRequestBindingTest extends TestCase
{
    protected function setUp(): void
    {
        WpStub::reset();
    }

    private function container(): Container
    {
        $container = new Container();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Container::class, $container);

        return $container;
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function request(array $body = [], array $headers = []): Request
    {
        return new Request(
            query: [],
            body: $body,
            server: ['REQUEST_METHOD' => 'POST'],
            headers: $headers
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: RecordingController, 1: mixed}
     */
    private function call(string $action, array $body, array $headers = []): array
    {
        $controller = new RecordingController();
        $request = $this->request($body, $headers);

        $result = (new ParameterBinder($this->container()))->call(
            $controller,
            $action,
            $request,
            new HttpParameterSource($request, []),
            Request::class
        );

        return [$controller, $result];
    }

    public function test_a_valid_form_request_reaches_the_action(): void
    {
        [$controller, $result] = $this->call('store', ['email' => 'a@b.test', 'name' => 'Ada']);

        $this->assertSame(1, $controller->calls);
        $this->assertSame('stored', $result);
    }

    public function test_the_action_receives_the_validated_form_request(): void
    {
        [$controller] = $this->call('store', ['email' => 'a@b.test', 'name' => 'Ada']);

        $this->assertNotNull($controller->received);
        $this->assertSame(['email' => 'a@b.test', 'name' => 'Ada'], $controller->received);
    }

    /**
     * The assertion this whole file exists for.
     */
    public function test_an_invalid_form_request_never_reaches_the_action(): void
    {
        [$controller, $result] = $this->call('store', ['email' => 'nope', 'name' => 'Ada']);

        $this->assertSame(0, $controller->calls, 'The action must not run on invalid input.');
        $this->assertNull($controller->received);
        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    public function test_a_json_client_gets_a_422_from_the_binder(): void
    {
        [$controller, $result] = $this->call(
            'store',
            ['email' => 'nope', 'name' => 'Ada'],
            ['accept' => 'application/json']
        );

        $this->assertSame(0, $controller->calls);
        $this->assertInstanceOf(JsonResponse::class, $result);
        $this->assertSame(422, $result->status());
    }

    public function test_the_action_still_receives_its_other_parameters(): void
    {
        [$controller, $result] = $this->call('update', ['email' => 'a@b.test', 'name' => 'Ada']);

        $this->assertSame(1, $controller->calls);
        $this->assertSame('stored', $result);
    }

    /**
     * The bind does not stop at the failed form request.
     *
     * A parameter declared *after* the form request is still bound. This is the
     * property that makes the argument list independent of where in the signature
     * the form request appears — an early `return []` bound everything before the
     * form and nothing after it, so moving the parameter changed which arguments
     * the action would have received.
     *
     * Asserted through a route defect rather than through the arguments, because
     * the action never runs on a failed form: the observable is that the *other*
     * parameter's own rule still fired.
     */
    public function test_a_failed_form_request_does_not_stop_the_rest_of_the_bind(): void
    {
        // `id` is declared as `int`, and the route supplies a non-numeric
        // placeholder — a route-definition defect that has nothing to do with the
        // form. Binding the whole signature is what surfaces it.
        $controller = new RecordingController();
        $request = $this->request(['email' => 'nope', 'name' => 'Ada']);

        $this->expectException(RouteResolutionException::class);
        $this->expectExceptionMessage('id');

        (new ParameterBinder($this->container()))->call(
            $controller,
            'storeWithId',
            $request,
            new HttpParameterSource($request, ['id' => 'not-a-number']),
            Request::class
        );
    }

    /**
     * The other half of the same rule: when the remaining parameters *do* bind,
     * the failure is still a failure and the action still does not run.
     *
     * Together with the test above this pins the shape — the loop always
     * completes, and the recorded response still wins.
     */
    public function test_a_failed_form_request_still_leaves_the_other_parameters_bindable(): void
    {
        $controller = new RecordingController();
        $request = $this->request(['email' => 'nope', 'name' => 'Ada']);

        $result = (new ParameterBinder($this->container()))->call(
            $controller,
            'storeWithId',
            $request,
            new HttpParameterSource($request, ['id' => '7']),
            Request::class
        );

        $this->assertSame(0, $controller->calls, 'The action must still not run.');
        $this->assertInstanceOf(RedirectResponse::class, $result);
    }

    /**
     * A dispatch that throws must not leave a failure response armed.
     *
     * This is the case the review named: the binder is long-lived, and the two
     * places that consume the short-circuit were the only places that cleared it.
     * A throw out of binding — here from the route defect above — used to leave
     * the response in place, and the *next* request on the same worker would be
     * refused with it.
     */
    public function test_a_throw_out_of_binding_does_not_poison_the_next_call(): void
    {
        $binder = new ParameterBinder($this->container());

        // Arms the short-circuit (the form fails) and then throws (the `id`
        // parameter cannot be bound).
        $bad = $this->request(['email' => 'nope', 'name' => 'Ada']);

        try {
            $binder->call(
                new RecordingController(),
                'storeWithId',
                $bad,
                new HttpParameterSource($bad, ['id' => 'not-a-number']),
                Request::class
            );

            $this->fail('The unbindable parameter should have raised.');
        } catch (RouteResolutionException) {
            // Expected — and the point is what the *binder* still holds.
        }

        $good = $this->request(['email' => 'a@b.test', 'name' => 'Ada']);
        $controller = new RecordingController();

        $result = $binder->call(
            $controller,
            'store',
            $good,
            new HttpParameterSource($good, []),
            Request::class
        );

        $this->assertSame('stored', $result, 'The stale failure must not be returned.');
        $this->assertSame(1, $controller->calls, 'The next request must run its action.');
    }

    /**
     * The binder is a long-lived collaborator on a resolver a worker reuses, so a
     * failure left in place would refuse the next request. This is the bug that
     * only shows up under a persistent process.
     */
    public function test_a_failure_does_not_poison_the_next_call(): void
    {
        $container = $this->container();
        $binder = new ParameterBinder($container);

        $bad = $this->request(['email' => 'nope', 'name' => 'Ada']);
        $first = $binder->call(
            new RecordingController(),
            'store',
            $bad,
            new HttpParameterSource($bad, []),
            Request::class
        );

        $good = $this->request(['email' => 'a@b.test', 'name' => 'Ada']);
        $controller = new RecordingController();
        $second = $binder->call(
            $controller,
            'store',
            $good,
            new HttpParameterSource($good, []),
            Request::class
        );

        $this->assertInstanceOf(RedirectResponse::class, $first);
        $this->assertSame('stored', $second);
        $this->assertSame(1, $controller->calls);
    }

    /**
     * A form request has to validate against the framework's Request, which is
     * what both dispatch paths build. A hand-wired caller passing something else
     * gets a message naming the mismatch rather than a silent pass over an empty
     * payload.
     */
    public function test_a_non_request_dispatch_is_refused(): void
    {
        $this->expectException(RouteResolutionException::class);

        $source = new class implements \Framework\Routing\ParameterSource {
            public function routeParameters(): array
            {
                return [];
            }

            public function value(string $name): mixed
            {
                return null;
            }
        };

        (new ParameterBinder($this->container()))->call(
            new RecordingController(),
            'store',
            new \stdClass(),
            $source,
            Request::class
        );
    }

    public function test_a_closure_handler_is_also_short_circuited(): void
    {
        $request = $this->request(['email' => 'nope', 'name' => 'Ada']);
        $called = false;

        $handler = function (BinderStoreRequest $form) use (&$called): string {
            $called = true;

            return 'ran';
        };

        $result = (new ParameterBinder($this->container()))->callCallable(
            $handler,
            $request,
            new HttpParameterSource($request, []),
            Request::class
        );

        $this->assertFalse($called, 'A closure typed against a form request must not run on invalid input.');
        $this->assertInstanceOf(RedirectResponse::class, $result);
    }
}

/**
 * A controller whose only interesting property is how many times it ran.
 */
final class RecordingController
{
    public int $calls = 0;

    /** @var array<string, mixed>|null */
    public ?array $received = null;

    public function store(BinderStoreRequest $form): string
    {
        $this->calls++;
        $this->received = $form->validated();

        return 'stored';
    }

    public function update(BinderStoreRequest $form, string $id = 'x'): string
    {
        $this->calls++;
        $this->received = $form->validated();

        return 'stored';
    }

    /**
     * A form request *followed* by a parameter the route has to supply.
     *
     * The order is the point: with an early `return` on a failed form, a
     * parameter declared after it was never bound, so a defect in the route's own
     * placeholder was invisible until the form was fixed. `int $id` is strict
     * about a non-numeric placeholder, which is what makes the difference
     * observable.
     */
    public function storeWithId(BinderStoreRequest $form, int $id): string
    {
        $this->calls++;
        $this->received = $form->validated();

        return 'stored';
    }
}
