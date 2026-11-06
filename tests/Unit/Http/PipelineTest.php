<?php
declare(strict_types=1);

namespace Tests\Unit\Http;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use Closure;
use Framework\Http\Exceptions\HttpException;
use Framework\Http\Middleware\MiddlewareAliases;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;
use Tests\Unit\Http\Fixtures\AddingMiddleware;
use Tests\Unit\Http\Fixtures\BehindMiddleware;
use Tests\Unit\Http\Fixtures\InnerMiddleware;
use Tests\Unit\Http\Fixtures\OuterMiddleware;
use Tests\Unit\Http\Fixtures\ParameterizedMiddleware;
use Tests\Unit\Http\Fixtures\RecordingMiddleware;
use Tests\Unit\Http\Fixtures\RewritingMiddleware;
use Tests\Unit\Http\Fixtures\ShortCircuitingMiddleware;
use Tests\Unit\Http\Fixtures\SourceMiddleware;
use Tests\Unit\Http\Fixtures\TerminatingMiddleware;

final class PipelineTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        WpStub::reset();
        RecordingMiddleware::reset();

        // The same self-bindings the real container has. Without them a
        // middleware type-hinting ContainerInterface could not be built — see
        // the container-semantics note in the routing tests.
        $this->container = new Container();
        $this->container->instance(ContainerInterface::class, $this->container);
        $this->container->instance(Container::class, $this->container);
    }

    // ------------------------------------------------------------------- onion

    /**
     * The order is the whole contract: middleware listed first is outermost, so
     * it enters first and leaves last. Reversing the reduce — or forgetting to
     * reverse it — produces a stack that still "works" and runs inside out.
     *
     * Each layer is a distinct class name, because the pipeline resolves
     * middleware by class: two entries of the same class are two resolutions of
     * the same binding, which cannot be given different labels.
     */
    public function test_the_stack_unwinds_in_reverse_order(): void
    {
        $this->label([
            OuterMiddleware::class => 'outer',
            InnerMiddleware::class => 'inner',
        ]);

        $this->pipeline([OuterMiddleware::class, InnerMiddleware::class])
            ->then($this->terminal());

        $this->assertSame(
            ['outer:in', 'inner:in', 'inner:out', 'outer:out'],
            RecordingMiddleware::$log
        );
    }

    public function test_sending_an_empty_stack_reaches_the_destination(): void
    {
        $response = $this->pipeline([])->then($this->terminal());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(['reached' => true], $response->data());
        $this->assertSame([], RecordingMiddleware::$log);
    }

    // ------------------------------------------------------------ short circuit

    public function test_a_middleware_can_answer_without_reaching_the_destination(): void
    {
        $reached = false;

        $response = $this->pipeline([ShortCircuitingMiddleware::class])
            ->then(function (Request $request) use (&$reached): JsonResponse {
                $reached = true;

                return new JsonResponse(['destination' => true]);
            });

        $this->assertFalse($reached, 'The destination must not run after a short circuit.');
        $this->assertSame(['error' => 'short_circuit'], $response->data());
        $this->assertSame(403, $response->status());
    }

    /**
     * The middleware *behind* a short circuit must never be resolved, let alone
     * run. Asserted by binding it to a factory that records the resolution —
     * a log-based assertion alone would pass if the middleware ran and simply
     * failed to append.
     */
    public function test_a_middleware_behind_a_short_circuit_is_never_resolved(): void
    {
        $resolved = false;

        $this->container->bind(
            BehindMiddleware::class,
            function () use (&$resolved): RecordingMiddleware {
                $resolved = true;

                return new RecordingMiddleware('behind');
            }
        );

        $this->pipeline([ShortCircuitingMiddleware::class, BehindMiddleware::class])
            ->then($this->terminal());

        $this->assertFalse($resolved, 'A middleware behind a short circuit must not be built.');
        $this->assertSame([], RecordingMiddleware::$log);
    }

    // --------------------------------------------------------- response access

    /**
     * The round trip is the reason this pipeline exists rather than a plain
     * beforeEach-style loop: middleware must be able to see — and change — what
     * the destination produced.
     */
    public function test_a_middleware_sees_the_response_on_the_way_back_out(): void
    {
        $this->container->bind(
            AddingMiddleware::class,
            fn (): AddingMiddleware => new AddingMiddleware()
        );

        $response = $this->pipeline([AddingMiddleware::class])->then($this->terminal());

        $this->assertSame(
            ['X-Middleware' => ',outer'],
            $response->options()['headers'] ?? null
        );
    }

    public function test_a_middleware_can_change_the_status_on_the_way_out(): void
    {
        $this->container->bind(RewritingMiddleware::class, fn (): RewritingMiddleware => new RewritingMiddleware());

        $response = $this->pipeline([RewritingMiddleware::class])->then($this->terminal());

        $this->assertSame(202, $response->status());
    }

    public function test_a_middleware_can_replace_the_request_the_destination_sees(): void
    {
        $this->container->bind(RewritingMiddleware::class, fn (): RewritingMiddleware => new RewritingMiddleware());

        $seen = null;

        $this->pipeline([RewritingMiddleware::class])
            ->then(function (Request $request) use (&$seen): JsonResponse {
                $seen = $request->header('x-middleware-tag');

                return new JsonResponse([]);
            });

        $this->assertSame('rewritten', $seen);
    }

    // ------------------------------------------------------- terminating layer

    /**
     * A middleware that never delegates still declares `$next` — dropping it is
     * a fatal, not a convenience — and its return value is the response. The
     * method it reads back out is the proof the pipeline handed it the request
     * that was *sent*, not a freshly built one.
     */
    public function test_a_terminating_middleware_answers_from_the_sent_request(): void
    {
        $this->container->bind(TerminatingMiddleware::class, fn (): TerminatingMiddleware => new TerminatingMiddleware());

        $response = $this->pipeline([TerminatingMiddleware::class])
            ->send(new Request(server: ['REQUEST_METHOD' => 'PATCH']))
            ->then($this->terminal());

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(['terminated' => 'PATCH'], $response->data());
    }

    // ------------------------------------------------------- dependency wiring

    /**
     * Middleware is built by the container, so a constructor dependency is
     * injected — the same guarantee the routing middleware gives.
     *
     * Proven through a service the middleware *requires*, not through a binding
     * on the middleware class itself: binding the class would prove only that a
     * factory was honoured, and a pipeline that called `new $class()` on an
     * unbound class would pass that. Here the constructor parameter is typed, so
     * `new` would throw.
     */
    public function test_a_middleware_constructor_dependency_is_injected(): void
    {
        $this->container->bind(
            SourceMiddleware::class,
            fn ($container): SourceMiddleware => new SourceMiddleware($container->resolve(RecordingMiddleware::class))
        );

        RecordingMiddleware::$log = [];

        // RecordingMiddleware is bound as a factory carrying a label, so if the
        // dependency really was resolved through the container the log will show
        // that label rather than the "unlabelled" constructor default.
        $this->container->bind(
            RecordingMiddleware::class,
            static fn (): RecordingMiddleware => new RecordingMiddleware('injected')
        );

        $this->pipeline([SourceMiddleware::class])->then($this->terminal());

        $this->assertSame(['injected:in', 'injected:out'], RecordingMiddleware::$log);
    }

    // --------------------------------------------------------------- contracts

    /**
     * A destination is a closure, so the compiler checks nothing about it — this
     * is the case the guard actually exists for. A middleware cannot produce it:
     * the interface declares `: ResponseInterface` and PHP permits no loosening
     * of a return type, so a class trying to return null is a fatal at load.
     */
    public function test_a_destination_returning_nothing_is_reported(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('A pipeline handler returned [null]');

        $this->pipeline([])->then(static fn (Request $request) => null);
    }

    public function test_a_class_that_is_not_middleware_is_rejected(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('must implement');

        $this->pipeline([\stdClass::class])->then($this->terminal());
    }

    public function test_a_pipeline_without_a_request_is_refused(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('needs a request');

        (new Pipeline($this->container))->then($this->terminal());
    }

    // ------------------------------------------------------ middleware parameters

    public function test_a_parameter_is_passed_to_the_middleware(): void
    {
        ParameterizedMiddleware::reset();

        $this->pipeline([ParameterizedMiddleware::class . ':manage_options'])->then($this->terminal());

        $this->assertSame([['manage_options']], ParameterizedMiddleware::$received);
    }

    public function test_parameters_are_passed_to_the_middleware(): void
    {
        ParameterizedMiddleware::reset();

        $this->pipeline([ParameterizedMiddleware::class . ':leads_store,my_field'])
            ->then($this->terminal());

        $this->assertSame([['leads_store', 'my_field']], ParameterizedMiddleware::$received);
    }

    /**
     * A bare class name is the ordinary case and must keep working: the
     * middleware receives an empty variadic rather than being skipped.
     */
    public function test_a_class_with_no_parameter_receives_none(): void
    {
        ParameterizedMiddleware::reset();

        $this->pipeline([ParameterizedMiddleware::class])->then($this->terminal());

        $this->assertSame([[]], ParameterizedMiddleware::$received);
    }

    /**
     * Split at the *first* colon only. A parameter that is itself a URL — or any
     * other colon-bearing string — must survive intact, because splitting on
     * every colon would shred it into pieces the middleware never asked for.
     */
    public function test_only_the_first_colon_separates_class_from_parameters(): void
    {
        ParameterizedMiddleware::reset();

        $this->pipeline([ParameterizedMiddleware::class . ':https://example.test/a'])
            ->then($this->terminal());

        $this->assertSame([['https://example.test/a']], ParameterizedMiddleware::$received);
    }

    /**
     * The parameters on one layer must not leak to another. A pipeline whose
     * `count()` grew with the stack would otherwise walk off the end.
     */
    public function test_each_layer_receives_only_its_own_parameters(): void
    {
        ParameterizedMiddleware::reset();

        $this->pipeline([
            ParameterizedMiddleware::class . ':outer',
            ParameterizedMiddleware::class . ':inner',
        ])->then($this->terminal());

        $this->assertSame([['outer'], ['inner']], ParameterizedMiddleware::$received);
    }

    /**
     * The failure this whole check exists for: the container drops an override a
     * method has no parameter for, **silently**. A silently dropped
     * `capability:manage_options` is an authorization check that never runs, so
     * the pipeline refuses it instead of dispatching a request the routes file
     * claims is protected.
     */
    public function test_parameters_given_to_a_middleware_that_takes_none_are_refused(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('declares no variadic argument');

        $this->pipeline([RecordingMiddleware::class . ':unexpected'])->then($this->terminal());
    }

    /**
     * The message has to name both the middleware and what it was given —
     * a report that says only "invalid middleware parameters" leaves the reader
     * to find the one of forty entries that is wrong.
     */
    public function test_the_refusal_names_the_middleware_and_its_parameters(): void
    {
        try {
            $this->pipeline([RecordingMiddleware::class . ':manage_options'])->then($this->terminal());
        } catch (HttpException $e) {
            $this->assertStringContainsString(RecordingMiddleware::class, $e->getMessage());
            $this->assertStringContainsString('manage_options', $e->getMessage());

            return;
        }

        $this->fail('Expected the pipeline to refuse parameters a middleware cannot receive.');
    }

    /**
     * An anonymous class name contains a colon — `class@anonymous ...php:12$0` —
     * so a pipeline that split on the first colon before checking whether the
     * whole entry was a class would truncate it and fail to resolve a middleware
     * that is perfectly valid. A fixture built with `new class extends ...` is
     * the ordinary way to hit this.
     */
    public function test_an_anonymous_class_name_is_not_split_at_its_colon(): void
    {
        $middleware = new class implements \Framework\Http\Contracts\Middleware {
            public bool $reached = false;

            public function handle(Request $request, Closure $next): \Framework\Http\Contracts\ResponseInterface
            {
                $this->reached = true;

                return $next($request);
            }
        };

        $this->container->instance($middleware::class, $middleware);

        $this->pipeline([$middleware::class])->then($this->terminal());

        $this->assertTrue($middleware->reached, 'The anonymous middleware was never dispatched.');
    }

    // ------------------------------------------------------------------- aliases

    public function test_an_alias_resolves_to_its_class(): void
    {
        ParameterizedMiddleware::reset();

        $aliases = new MiddlewareAliases();
        $aliases->alias('parameterized', ParameterizedMiddleware::class);

        $this->pipelineWithAliases($aliases, ['parameterized:manage_options'])
            ->then($this->terminal());

        $this->assertSame([['manage_options']], ParameterizedMiddleware::$received);
    }

    /**
     * A loadable class name wins over an alias of the same name. For a framework
     * that ships to other projects this is not hypothetical: a project may define
     * its own `CheckCapability`, and a registry that rewrote the name would
     * quietly substitute the framework's for the project's.
     */
    public function test_a_real_class_name_is_not_rewritten_by_an_alias_of_the_same_name(): void
    {
        ParameterizedMiddleware::reset();

        $aliases = new MiddlewareAliases();
        // An alias whose name is a real, loadable class — the adversarial case.
        $aliases->alias(ParameterizedMiddleware::class, RecordingMiddleware::class);

        $this->pipelineWithAliases($aliases, [ParameterizedMiddleware::class])
            ->then($this->terminal());

        $this->assertSame([[]], ParameterizedMiddleware::$received);
    }

    /**
     * Without a registry, every entry is a class name. Existing constructions of
     * `new Pipeline($container)` must keep working untouched.
     */
    public function test_an_unknown_alias_falls_through_to_the_container(): void
    {
        $aliases = new MiddlewareAliases();

        $this->expectException(\Throwable::class);

        $this->pipelineWithAliases($aliases, ['no-such-middleware'])->then($this->terminal());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<int, string> $middleware
     */
    private function pipeline(array $middleware): Pipeline
    {
        return (new Pipeline($this->container))
            ->send(new Request())
            ->through($middleware);
    }

    /**
     * A pipeline carrying an alias registry.
     *
     * @param array<int, string> $middleware
     */
    private function pipelineWithAliases(MiddlewareAliases $aliases, array $middleware): Pipeline
    {
        return (new Pipeline($this->container, $aliases))
            ->send(new Request())
            ->through($middleware);
    }

    /**
     * Bind one factory per class, each producing a RecordingMiddleware with its
     * own label.
     *
     * The label goes in through a factory rather than a constructor argument,
     * because the pipeline resolves middleware by class name and has no way to
     * pass a parameter — which is itself why the documented way to configure a
     * middleware is a subclass with its own constructor.
     *
     * @param array<class-string<RecordingMiddleware>, string> $labels
     */
    private function label(array $labels): void
    {
        foreach ($labels as $class => $label) {
            $this->container->bind(
                $class,
                static fn (): RecordingMiddleware => new RecordingMiddleware($label)
            );
        }
    }

    /**
     * @return callable(Request): JsonResponse
     */
    private function terminal(): callable
    {
        return static fn (Request $request): JsonResponse => new JsonResponse(['reached' => true]);
    }
}
