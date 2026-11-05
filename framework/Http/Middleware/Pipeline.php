<?php
declare(strict_types=1);

namespace Framework\Http\Middleware;

use Closure;
use DevLinkStudios\WPServiceContainer\Container;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Exceptions\HttpException;
use Framework\Http\Request;

/**
 * Passes a request through a stack of middleware to a destination.
 *
 * The stack is an onion: each layer runs its own code, calls `$next`, and then
 * gets the response back on the way out. That round trip is the point — a layer
 * can add a CORS header or a cookie to whatever the destination produced, and
 * can short-circuit by returning a response without calling `$next` at all.
 *
 *     $pipeline->send($request)
 *         ->through([VerifyAdminNonce::class, Authenticate::class])
 *         ->then(fn (Request $request) => new ViewResponse($path));
 *
 * ## Why this is a separate layer from Routing middleware
 *
 * `Framework\Routing\Contracts\Middleware` runs inside WordPress's
 * `permission_callback`, which is a yes/no seam WordPress owns; it can only
 * deny. This pipeline is for requests the application dispatches itself — an
 * admin-post handler, an AJAX action, a shortcode that renders a form — where
 * the application owns the response and middleware needs to be able to rewrite
 * the request and decorate the response. The two do not call each other.
 *
 * ## A terminating middleware still declares `$next`
 *
 * A middleware that always answers — a rejection, a cache hit — declares
 * `handle(Request $request, Closure $next)` and never calls `$next`. Dropping
 * the parameter is **not** an option: PHP rejects an implementation whose
 * signature omits a parameter the interface requires, at class-load time. The
 * unused parameter is the price of a contract the language enforces.
 */
final class Pipeline
{
    /**
     * Middleware classes for this pass, outermost first.
     *
     * @var list<class-string<Middleware>>
     */
    private array $middleware = [];

    /**
     * The request this pipeline will send.
     */
    private ?Request $request = null;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Set the request to send.
     */
    public function send(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Set the middleware stack. Replaces any previously set stack.
     *
     * @param array<int, class-string<Middleware>> $middleware
     */
    public function through(array $middleware): self
    {
        $this->middleware = array_values($middleware);

        return $this;
    }

    /**
     * Append middleware to the stack.
     *
     * @param class-string<Middleware> ...$middleware
     */
    public function pipe(string ...$middleware): self
    {
        foreach ($middleware as $class) {
            $this->middleware[] = $class;
        }

        return $this;
    }

    /**
     * Run the stack, ending at $destination.
     *
     * The pipeline instance is single-use in practice — built, sent, discarded —
     * but sending one twice re-runs the stack from the top, which is what a test
     * comparing two stacks wants.
     *
     * @param callable(Request): ResponseInterface $destination
     *
     * @throws HttpException When $destination returns something that is not a
     *                       response, or the stack was never given a request.
     */
    public function then(callable $destination): ResponseInterface
    {
        if ($this->request === null) {
            throw new HttpException('A pipeline needs a request before it can be sent.');
        }

        $pipeline = array_reduce(
            // Reversed, so the first middleware listed is the outermost layer —
            // the order a reader expects and the order middleware is declared in.
            array_reverse($this->middleware),
            fn (Closure $next, string $middleware): Closure => $this->carry($middleware, $next),
            // The innermost layer: what is left after every middleware has
            // delegated. Wrapped in a closure taking the request so the whole
            // chain has one shape.
            fn (Request $request): ResponseInterface => $this->toResponse($destination($request))
        );

        return $pipeline($this->request);
    }

    /**
     * Build the closure for one middleware: call it, and make sure a response
     * comes back.
     *
     * @param class-string<Middleware>            $middleware
     * @param Closure(Request): ResponseInterface $next
     * @return Closure(Request): ResponseInterface
     */
    private function carry(string $middleware, Closure $next): Closure
    {
        return function (Request $request) use ($middleware, $next): ResponseInterface {
            $instance = $this->container->resolve($middleware);

            if (!$instance instanceof Middleware) {
                throw new HttpException(sprintf(
                    'Middleware [%s] must implement [%s].',
                    $middleware,
                    Middleware::class
                ));
            }

            // Invoked through the container so the middleware's constructor
            // dependencies are injected.
            //
            // The overrides are keyed by the parameter names the **interface**
            // declares. That is the whole reason those names are part of the
            // contract rather than a style choice: the container matches an
            // override against `$parameter->getName()` and nothing else — an
            // integer key is ignored outright, and a `$request` spelling resolves
            // to nothing — so a middleware that renames `$request` or `$next` gets
            // `BindingResolutionException: Unresolvable dependency` the moment the
            // stack runs. There is no fallback by type: `Closure` is looked up as
            // a class and fails as "[Closure] is not instantiable".
            //
            // Calling the method directly would be duck-typed and immune to the
            // naming — and would lose the constructor injection, which is the
            // property tests in this repository actually depend on.
            $response = $this->container->call([$instance, 'handle'], [
                'request' => $request,
                'next' => $next,
            ]);

            return $this->toResponse($response);
        };
    }

    /**
     * Coerce a handler's return value into a response.
     *
     * Reached from two places with different guarantees, which is why the check
     * is here rather than assumed:
     *
     *  - **A middleware.** The interface declares `: ResponseInterface` and PHP
     *    allows no loosening of a return type, so anything that implements it
     *    cannot return null or a WP_REST_Response — a class that tried would be
     *    a fatal at load, not a surprise at runtime. For this caller the check
     *    is a cheap assertion against a future signature change.
     *  - **The destination.** A closure, so the compiler checks nothing. This is
     *    the case the check actually exists for: a caller that writes
     *    `then(fn ($request) => $this->service->handle($request))` and gets back
     *    an array has made a mistake that is otherwise reported several frames
     *    later, by code that cannot name the offending handler.
     *
     * @param mixed $result
     *
     * @throws HttpException When the handler answered with something that is not
     *                       a ResponseInterface.
     */
    private function toResponse(mixed $result): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $result;
        }

        throw new HttpException(sprintf(
            'A pipeline handler returned [%s]; expected an instance of [%s]. '
            . 'A middleware that does not answer the request must return $next($request).',
            get_debug_type($result),
            ResponseInterface::class
        ));
    }
}
