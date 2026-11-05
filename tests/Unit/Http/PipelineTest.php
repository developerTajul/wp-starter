<?php
declare(strict_types=1);

namespace Tests\Unit\Http;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use Framework\Http\Exceptions\HttpException;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;
use Tests\Unit\Http\Fixtures\AddingMiddleware;
use Tests\Unit\Http\Fixtures\BehindMiddleware;
use Tests\Unit\Http\Fixtures\InnerMiddleware;
use Tests\Unit\Http\Fixtures\OuterMiddleware;
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
