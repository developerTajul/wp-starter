<?php
declare(strict_types=1);

namespace Framework\Routing;

use Framework\Routing\Adapters\WordPressRouterAdapter;
use InvalidArgumentException;
use RuntimeException;

/**
 * Turns a route name into the URL WordPress actually serves it at.
 *
 * ## Laravel's API, WordPress's URLs
 *
 * The developer experience is deliberately Laravel's:
 *
 *     route('leads.index');                    // .../wp-admin/admin.php?page=leads
 *     route('leads.store');                    // .../wp-admin/admin-post.php?action=leads_store
 *     route('leads.show', ['id' => 42]);       // .../wp-json/wp-mini-laravel/v1/leads/42
 *
 * What sits underneath is not. Laravel has one router and one URL space; a plugin
 * has four mechanisms with four unrelated ways of addressing a resource, and no
 * shared prefix:
 *
 *  - **admin screen** — `admin.php?page={slug}`, a query parameter. The slug *is*
 *    the route path, because that is what `add_menu_page()` was given.
 *  - **form handler** — `admin-post.php?action={action}`, also a query parameter,
 *    but a different file and a different parameter.
 *  - **REST endpoint** — `rest_url('{namespace}/{path}')`, a real path, with
 *    placeholders substituted.
 *  - **front-end page** — `home_url('{path}')`, a real path relative to the site.
 *
 * That mapping is this class. Which mechanism applies is not a guess: it is the
 * surface the route was published under, read from the same option bag the
 * adapters wrote, through the same {@see AdminPage} view they read — so a URL and
 * the screen it points at cannot disagree about what the slug is.
 *
 * ## The action name is read, not re-derived
 *
 * `admin_post_{action}` names are derived from the route's name or path, and the
 * derivation is non-obvious (non-POST verbs get a suffix so PUT and DELETE do not
 * share one hook — {@see WordPressRouterAdapter} explains why). Re-implementing it
 * here would create a second rule that drifts from the first, and the symptom
 * would be a form posting to an action nothing listens for: a silent no-op
 * submission.
 *
 * So the generator reads `action` off the route, where `resolveAdminActions()`
 * already wrote the *final* name at flush time. That is the same value WordPress
 * is hooked on, because it is the literal string the hook was built from.
 *
 * ## Two ways to be wrong, told apart
 *
 * An unknown name and a name with a missing parameter are different mistakes and
 * get different messages. A missing parameter, in particular, must not be quietly
 * dropped: `leads/{id}` with no `id` would otherwise generate `/leads/` — a URL
 * that is valid, resolves to nothing, and looks fine in the template that produced
 * it.
 */
final class UrlGenerator
{
    /**
     * The REST namespace every API route is published under.
     *
     * Passed in rather than read from config here, because this class is also
     * built directly by tests and by `route:list`, neither of which has a booted
     * application behind it.
     */
    public function __construct(
        private readonly NamedRoutes $named,
        private readonly string $restNamespace = 'wp-mini-laravel/v1'
    ) {
    }

    /**
     * The URL for a named route.
     *
     * ## Relative by default, because a plugin cannot know its own host
     *
     * Every branch below is built with a WordPress URL function — `admin_url()`,
     * `rest_url()`, `home_url()` — which is the only correct answer: the scheme, the
     * host, the subdirectory a site is installed under, and whether it is behind a
     * proxy are all things WordPress resolves and a plugin cannot. So that half is
     * never re-implemented here.
     *
     * ## What happens without WordPress loaded
     *
     * Those functions are WordPress's, so they do not exist in a unit test or a CLI
     * process. Rather than fail, the generator returns the **path** it would have
     * passed — `admin.php?page=leads`, `/wp-json/wp-mini-laravel/v1/leads/42` — and
     * that is genuinely useful: it is the part this class computes, and it is what
     * a test can assert without stubbing a site URL. The alternative, throwing,
     * would mean `route()` could not be called from a WP-CLI command at all.
     *
     * @param array<string, scalar> $parameters Placeholder values, keyed by name.
     *
     * @throws InvalidArgumentException When no route carries the name, or when the
     *                                  name is claimed by two domains.
     * @throws RuntimeException         When a path placeholder has no value.
     */
    public function route(string $name, array $parameters = []): string
    {
        $ambiguity = $this->named->ambiguity($name);

        // Reported before the lookup, because the lookup would otherwise pick one
        // of them and return a URL that is right by luck. A link to the wrong
        // screen is worse than an exception: it works, so nobody investigates.
        if ($ambiguity !== []) {
            throw new InvalidArgumentException(sprintf(
                'Route name [%s] is ambiguous: it is declared in both the [%s] domains. '
                . 'Give one of them a different name with ->name().',
                $name,
                implode('] and [', $ambiguity)
            ));
        }

        $route = $this->named->find($name);

        if ($route === null) {
            throw new InvalidArgumentException($this->unknownNameMessage($name));
        }

        return $this->compile($route, $parameters);
    }

    /**
     * Whether a name resolves to a route.
     *
     * For a caller that wants to fall back rather than throw — a menu entry that
     * is optional, a template shared between a project that defines a route and
     * one that does not.
     */
    public function has(string $name): bool
    {
        return $this->named->ambiguity($name) === [] && $this->named->find($name) !== null;
    }

    /**
     * The URL for a route, by surface.
     *
     * ## Why a surface-less route has to be REST and not "whatever is left"
     *
     * The `default` arm is reached by a route that carries no surface option at
     * all, which is every route that never passed through a web registrar: a REST
     * route from `routes/api.php`, or a `Route` built by hand. REST is the only
     * mechanism those publish on, so the default is not a guess.
     *
     * What it must *not* absorb is a web route whose surface is unknown, and the
     * reason `SURFACE_PAGE` has an arm of its own is that it once did exactly that:
     * a named `page()` route fell through here, was compiled as a REST endpoint, and
     * generated `/wp-json/{namespace}/thank-you` — a URL that 404s, under a prefix
     * no theme or rewrite rule serves. It is the same class of mistake as
     * {@see compileAdminAction()}'s: the wrong WordPress mechanism produces a
     * plausible string, so nothing raises and the link is simply broken.
     *
     * @param array<string, scalar> $parameters
     */
    private function compile(Route $route, array $parameters): string
    {
        return match ($this->surfaceOf($route)) {
            WordPressRouterAdapter::SURFACE_ADMIN_PAGE => $this->compileAdminPage($route, $parameters),
            WordPressRouterAdapter::SURFACE_ADMIN_ACTION => $this->compileAdminAction($route),
            WordPressRouterAdapter::SURFACE_PAGE => $this->compileFrontEndPage($route, $parameters),
            default => $this->compileRestEndpoint($route, $parameters),
        };
    }

    /**
     * Which of WordPress's mechanisms this route is published on.
     *
     * Read from the route's own option, which {@see WebRouteRegistrar} stamped on
     * it when the routes file ran, and which the adapter's `flush()` reads too.
     *
     * ## A missing surface means REST, and inferring from the verb here would be wrong
     *
     * The tempting reading is that a route with no surface option never passed
     * through a web registrar, so ask the web adapter what it would have decided.
     * That is precisely backwards for a *REST* route, which also never passed
     * through one: `Route::get('leads', ...)` in `routes/api.php` carries no
     * surface, and the adapter's inference would call it a front-end page and
     * generate `home_url('leads')` — a URL on the site where no such page exists,
     * instead of the endpoint that was just registered.
     *
     * The distinction the adapter cannot make and this class must: `inferSurface()`
     * is a *web* adapter's rule about routes reaching a *web* adapter. Anything
     * arriving here without a surface did not come from a web registrar, and REST
     * is the only other surface — so `default` in {@see compile()} is the correct
     * answer, not a fallback of last resort.
     */
    private function surfaceOf(Route $route): string
    {
        $surface = $route->getOption(WordPressRouterAdapter::OPTION_SURFACE);

        return is_string($surface) ? $surface : '';
    }

    /**
     * `admin.php?page={slug}` — an admin screen.
     *
     * The slug is the route's path verbatim, because the path *is* the slug:
     * `add_menu_page()` was handed it unchanged, and a URL that transformed it
     * would point at a screen that does not exist.
     *
     * Placeholders are substituted when the path has them, so a screen registered
     * as `leads/{tab}` can be linked to per tab. WordPress puts the value in the
     * same `page` parameter — a sub-page of a screen is a different slug, not a
     * separate argument — so substitution happens before the parameter is built.
     *
     * @param array<string, scalar> $parameters
     */
    private function compileAdminPage(Route $route, array $parameters): string
    {
        $slug = $this->substitute($route, $parameters);

        return $this->url('admin_url', 'admin.php?page=' . rawurlencode($slug));
    }

    /**
     * `admin-post.php?action={action}` — a form handler.
     *
     * No substitution: an `admin_post_{action}` name cannot carry a value, and the
     * derivation drops placeholders for exactly that reason — `leads/{id}` names
     * the same handler whatever the id is, and the id travels as a form field. A
     * caller passing parameters anyway has misunderstood the mechanism, so passing
     * them is not silently tolerated.
     */
    private function compileAdminAction(Route $route): string
    {
        $action = $this->actionName($route);

        return $this->url('admin_url', 'admin-post.php?action=' . rawurlencode($action));
    }

    /**
     * `rest_url('{namespace}/{path}')` — a REST endpoint.
     *
     * The namespace is included because `rest_url()` does not know the plugin's,
     * and omitting it would produce a URL that 404s with a valid-looking shape.
     *
     * @param array<string, scalar> $parameters
     */
    private function compileRestEndpoint(Route $route, array $parameters): string
    {
        $path = $this->substitute($route, $parameters);

        return $this->url('rest_url', $this->restNamespace . '/' . $path);
    }

    /**
     * `home_url('{path}')` — a front-end page.
     *
     * ## A page path is a real path, so it takes `home_url()` and not the admin's
     *
     * The distinction that matters: an admin screen hides behind a query parameter
     * on `admin.php`, and a front-end page is a URL on the site itself. Prefixing
     * one with the other's base produces a URL under a directory nothing serves —
     * `/wp-admin/thank-you`, or `/wp-json/{ns}/thank-you` — which is why the surface
     * selects the function rather than the shape of the path deciding it.
     *
     * ## Placeholders become path segments, leftovers become a query string
     *
     * A placeholder in the path is substituted in place, because that is what the
     * route declares: `thank-you/{order}` is a path with a segment in it, and
     * WordPress matches it as one.
     *
     * Anything the caller passes that is *not* a placeholder has nowhere to go in
     * the path, so it is appended as a query string — the WordPress idiom for the
     * rest of a request's arguments, and what a template linking to
     * `page('leads')` with `['status' => 'new']` expects. Dropping them silently
     * would make a filtered link land on the unfiltered page, which looks like the
     * filter being broken rather than the link.
     *
     * @param array<string, scalar> $parameters
     */
    private function compileFrontEndPage(Route $route, array $parameters): string
    {
        $path = $this->substitute($route, $parameters);

        return $this->url('home_url', $path . $this->queryString($route, $parameters));
    }

    /**
     * The query string for parameters the path did not consume.
     *
     * Values are `rawurlencode`d individually and joined by `&`, rather than
     * handing an array to `http_build_query()`: that function uses `+` for spaces
     * under its default encoding, which is wrong in a URL path context and differs
     * from what {@see substitute()} does for the path segments it encodes. One
     * encoding rule for the whole generator is worth more than the brevity.
     */
    private function queryString(Route $route, array $parameters): string
    {
        $unused = array_diff_key($parameters, array_flip($route->getParameterNames()));

        $pairs = [];

        foreach ($unused as $key => $value) {
            if (!is_scalar($value)) {
                throw new RuntimeException(sprintf(
                    'Parameter [%s] for route [%s] must be a scalar. Got [%s].',
                    (string) $key,
                    $route->getName() ?? $route->getDisplayPath(),
                    get_debug_type($value)
                ));
            }

            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }

        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    /**
     * The URL for a front-end route, when the caller holds the route rather than
     * its name.
     *
     * Kept public and separate from the name lookup so a template part that has
     * been handed a `Route` can build its link without going back through the
     * registry. Unnamed routes have no name to look up, and this is the only way
     * to reach one.
     *
     * @param array<string, scalar> $parameters
     */
    public function page(Route $route, array $parameters = []): string
    {
        return $this->compileFrontEndPage($route, $parameters);
    }

    /**
     * The `admin_post_{action}` name for a route.
     *
     * Read off the route, where the adapter recorded the final name at flush time.
     * Falling back to the route's name covers a route that has not been flushed —
     * registered but never published, which is the normal state in a test that
     * builds a router by hand and never flushes it.
     */
    private function actionName(Route $route): string
    {
        $action = $route->getOption('action');

        if (is_string($action) && $action !== '') {
            return $action;
        }

        return (string) ($route->getName() ?? $route->getPath());
    }

    /**
     * Replace `{placeholder}` and `(?P<name>...)` segments with their values.
     *
     * Both spellings, because both are legal in a route path: `{id}` is what an
     * author writes and `(?P<id>...)` is what the REST adapter compiles it to. A
     * path that reached the generator through a compilation step would otherwise
     * generate a URL with a regex in it.
     *
     * The scan is {@see RouteCompiler::substitute()}'s, not a local regex. A
     * generator that disagrees with the compiler about where a placeholder ends
     * produces URLs that point at routes the router matches differently — and the
     * failure is a 404 on a link the application itself generated. The regex that
     * used to live here also required a character after the `:`, so
     * `posts/{id:}` produced no match at all: the placeholder was left in the URL
     * verbatim and the value the caller passed was reported as unused.
     *
     * @param array<string, scalar> $parameters
     *
     * @throws RuntimeException When a placeholder has no value.
     */
    private function substitute(Route $route, array $parameters): string
    {
        return RouteCompiler::substitute(
            $route->getPath(),
            function (string $key) use ($route, $parameters): string {
                if (!array_key_exists($key, $parameters)) {
                    throw new RuntimeException(sprintf(
                        'Route [%s] needs a value for parameter [{%s}] — route(\'%s\', [\'%s\' => ...]).',
                        $route->getName() ?? $route->getDisplayPath(),
                        $key,
                        (string) ($route->getName() ?? $route->getPath()),
                        $key
                    ));
                }

                $value = $parameters[$key];

                // Scalars only: an array here would stringify to "Array" and
                // produce a URL that looks plausible and goes nowhere.
                if (!is_scalar($value)) {
                    throw new RuntimeException(sprintf(
                        'Parameter [%s] for route [%s] must be a scalar. Got [%s].',
                        $key,
                        $route->getName() ?? $route->getDisplayPath(),
                        get_debug_type($value)
                    ));
                }

                return rawurlencode((string) $value);
            }
        );
    }

    /**
     * Apply WordPress's URL function, or return the path when it is absent.
     *
     * The `function_exists` check is what makes this callable outside a booted
     * WordPress — in a test, or under WP-CLI, where the URL helpers are not loaded
     * but `route()` still has to answer. See {@see route()} for why that is a
     * feature rather than a gap.
     */
    private function url(string $function, string $path): string
    {
        if (function_exists($function)) {
            /** @var string $url */
            $url = $function($path);

            return $url;
        }

        return $path;
    }

    /**
     * The message for a name nothing declares, listing what does exist.
     *
     * The available names are the whole value of the message: a typo is the usual
     * cause, and printing the real names turns a hunt through the routes files
     * into a glance. Capped, because a large project would otherwise get a wall of
     * names where the one missing fact is buried.
     */
    private function unknownNameMessage(string $name): string
    {
        $names = $this->named->names();

        if ($names === []) {
            return sprintf(
                'No route named [%s] is registered. No routes are registered at all yet — '
                . 'a routes file is read on admin_menu, rest_api_init and template_redirect, '
                . 'so a name is only knowable once one of them has fired.',
                $name
            );
        }

        $listed = array_slice($names, 0, 20);
        $suffix = count($names) > count($listed) ? sprintf(' (and %d more)', count($names) - count($listed)) : '';

        return sprintf(
            'No route named [%s] is registered. Available: %s%s.',
            $name,
            implode(', ', $listed),
            $suffix
        );
    }
}
