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
 * ## Middleware parameters
 *
 * A stack entry may carry parameters, separated from the class name by a colon:
 *
 *     $pipeline->send($request)
 *         ->through(['capability:manage_options', 'nonce:leads_store'])
 *         ->then($destination);
 *
 * Each entry is split at the **first** colon, so a parameter may itself contain
 * one (`'redirect:https://example.test'` passes `https://example.test`). The
 * parameters are handed to the middleware as extra arguments, which it receives
 * by declaring a variadic:
 *
 *     public function handle(Request $request, Closure $next, string ...$arguments)
 *
 * A bare class name passes nothing, and a middleware with no variadic parameter
 * ignores whatever it is given — which is why passing parameters to one is
 * **reported rather than shrugged off**. The container drops an override the
 * method has no parameter for, silently, and a silently-dropped
 * `capability:manage_options` is an unenforced authorization check. The pipeline
 * refuses it up front instead. See {@see carry()}.
 *
 * ## Aliases
 *
 * An entry may name a middleware by an alias — `'capability'`, `'nonce'` —
 * instead of by class. Aliases are resolved through {@see MiddlewareAliases},
 * which is optional: with none bound, every entry is treated as a class name and
 * a project that never registers an alias notices nothing.
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
     * Middleware classes for this pass, outermost first, with any parameters.
     *
     * Each entry is `[class-string, list<string>]` — the parameters already split
     * out — so the split happens once, when the stack is set, rather than on every
     * request through {@see carry()}.
     *
     * @var list<array{0: class-string<Middleware>, 1: list<string>}>
     */
    private array $middleware = [];

    /**
     * The request this pipeline will send.
     */
    private ?Request $request = null;

    /**
     * @param MiddlewareAliases|null $aliases Short names for middleware classes.
     *      Optional so the dozens of existing `new Pipeline($container)`
     *      constructions — tests, probes, a project building one by hand — keep
     *      working. When omitted, every stack entry is treated as a class name.
     */
    public function __construct(
        private readonly Container $container,
        private readonly ?MiddlewareAliases $aliases = null
    ) {
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
     * Entries are either a bare class name (or alias) or `'class:param:param'`;
     * see the class docblock. Each is split and resolved once, here, so a stack
     * configured with parameters costs no more per request than one without.
     *
     * @param array<int, class-string<Middleware>|string> $middleware
     */
    public function through(array $middleware): self
    {
        $this->middleware = array_map(
            fn (string $entry): array => $this->parseEntry($entry),
            array_values($middleware)
        );

        return $this;
    }

    /**
     * Append middleware to the stack.
     *
     * @param class-string<Middleware>|string ...$middleware
     */
    public function pipe(string ...$middleware): self
    {
        foreach ($middleware as $entry) {
            $this->middleware[] = $this->parseEntry($entry);
        }

        return $this;
    }

    /**
     * Split one stack entry into its class name and parameters.
     *
     * Split at the **first** colon only, so a parameter that is itself a URL or a
     * namespaced name survives intact: `'redirect:https://example.test'` yields
     * the class `redirect` and the single parameter `https://example.test`.
     * Splitting on every colon would shred it into three.
     *
     * ## A loadable class name is never split, colon or no colon
     *
     * This is checked *before* splitting, and it is not a micro-optimisation. An
     * **anonymous class name contains a colon** —
     * `class@anonymous /path/to/File.php:42$0` — and splitting on it would
     * truncate the name to something the container cannot resolve, failing a
     * middleware that is perfectly valid. A test fixture built with `new class
     * extends Authorize {...}` is the ordinary way to hit this, and the failure
     * would name a class that appears nowhere in the source.
     *
     * Checking `class_exists()` first also means a real class name always wins
     * over an alias or a parameter, which is the same precedence
     * {@see MiddlewareAliases::resolve()} states.
     *
     * An entry with no colon is a class name with no parameters, which is the
     * overwhelming common case and costs one `class_exists()`.
     *
     * **The split happens before the alias is resolved**, and the order is
     * load-bearing: `'capability:manage_options'` must become the entry
     * `capability` plus one parameter, and only then the framework's
     * `CheckCapability`. Resolving first would look up the alias
     * `'capability:manage_options'` — which does not exist, and could not, since
     * {@see MiddlewareAliases::alias()} refuses a name containing a colon.
     *
     * A trailing colon with nothing after it (`'capability:'`) yields one empty
     * parameter rather than none. That is deliberate: it is a misconfiguration
     * either way, and passing `''` gets it reported by whatever guard the
     * middleware performs rather than quietly becoming "no parameter was given",
     * which for `capability` would mean "no capability required".
     *
     * @return array{0: class-string<Middleware>, 1: list<string>}
     */
    private function parseEntry(string $entry): array
    {
        if (!str_contains($entry, ':') || class_exists($entry)) {
            /** @var class-string<Middleware> $resolved */
            $resolved = $this->resolveAlias($entry);

            return [$resolved, []];
        }

        [$class, $parameters] = explode(':', $entry, 2);

        /** @var class-string<Middleware> $resolved */
        $resolved = $this->resolveAlias($class);

        return [$resolved, explode(',', $parameters)];
    }

    /**
     * The class name for one entry, through the alias registry when there is one.
     *
     * A no-op without a registry, and a no-op for a name that is already a
     * loadable class — {@see MiddlewareAliases::resolve()} owns that ordering and
     * explains why it matters.
     */
    private function resolveAlias(string $entry): string
    {
        return $this->aliases?->resolve($entry) ?? $entry;
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
            fn (Closure $next, array $layer): Closure => $this->carry($layer[0], $layer[1], $next),
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
     * @param list<string>                        $arguments  Parameters from the stack entry.
     * @param Closure(Request): ResponseInterface $next
     * @return Closure(Request): ResponseInterface
     */
    private function carry(string $middleware, array $arguments, Closure $next): Closure
    {
        return function (Request $request) use ($middleware, $arguments, $next): ResponseInterface {
            $instance = $this->container->resolve($middleware);

            if (!$instance instanceof Middleware) {
                throw new HttpException(sprintf(
                    'Middleware [%s] must implement [%s].',
                    $middleware,
                    Middleware::class
                ));
            }

            $this->assertAcceptsArguments($instance, $middleware, $arguments);

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
            // `arguments` is the third override, and it is matched differently:
            // the container spreads an array override into a **variadic**
            // parameter of that name (Container::resolveDependencies()), which is
            // how a middleware receives `'capability:manage_options'` as a plain
            // `'manage_options'`. It is passed unconditionally — the container
            // ignores it for a method with no such parameter, and
            // assertAcceptsArguments() above has already refused the case where
            // ignoring it would silently drop a parameter the route supplied.
            //
            // Calling the method directly would be duck-typed and immune to the
            // naming — and would lose the constructor injection, which is the
            // property tests in this repository actually depend on.
            $response = $this->container->call([$instance, 'handle'], [
                'request' => $request,
                'next' => $next,
                'arguments' => $arguments,
            ]);

            return $this->toResponse($response);
        };
    }

    /**
     * Refuse a stack entry that passes parameters to middleware which takes none.
     *
     * The container drops an override a method has no parameter for, **silently**.
     * For most arguments that would be a harmless no-op; here it is not, because
     * the arguments this pipeline carries are guards — `capability:manage_options`
     * on a middleware that never sees it is an authorization check that silently
     * does not run, and the route looks protected in the routes file while being
     * open at runtime.
     *
     * So the mismatch is reported at dispatch, naming the middleware and what it
     * was given. It is a routes-file error — the parameter only ever comes from
     * `->middleware([...])` — and it is the kind that a silent drop would hide
     * indefinitely.
     *
     * Membership is decided by reflection on the resolved instance rather than by
     * parsing the source: a variadic of any name accepts the spread, and a
     * middleware inheriting `handle()` from a parameterized parent is accepted
     * too, which a source-level check would get wrong.
     *
     * @param list<string> $arguments
     *
     * @throws HttpException When $arguments is non-empty and the middleware
     *                       declares no variadic parameter to receive them.
     */
    private function assertAcceptsArguments(Middleware $instance, string $middleware, array $arguments): void
    {
        if ($arguments === []) {
            return;
        }

        try {
            $parameters = (new \ReflectionMethod($instance, 'handle'))->getParameters();
        } catch (\ReflectionException) {
            // Unreachable for a class that implements the interface, which
            // declares handle(). Left as a pass rather than a throw because the
            // call below would fail with a far clearer message than anything
            // invented here.
            return;
        }

        foreach ($parameters as $parameter) {
            if ($parameter->isVariadic()) {
                return;
            }
        }

        throw new HttpException(sprintf(
            'Middleware [%s] was given the parameter(s) [%s] but declares no variadic '
            . 'argument on handle() to receive them, so they would be dropped silently. '
            . 'Declare `handle(Request $request, Closure $next, string ...$arguments)`, '
            . 'or remove the parameters from the route.',
            $middleware,
            implode(', ', $arguments)
        ));
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
