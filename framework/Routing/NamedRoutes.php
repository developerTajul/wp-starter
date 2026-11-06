<?php
declare(strict_types=1);

namespace Framework\Routing;

use Framework\Routing\Contracts\RouterInterface;

/**
 * The named routes of every domain, in one place.
 *
 * ## Why this is not just "iterate the two routers"
 *
 * {@see UrlGenerator} needs to turn a name into a route, and the routes live in
 * two separate tables — the web adapter's and the REST one's — owned by two
 * adapters that deliberately know nothing about each other. Something has to hold
 * both, and the obvious candidate is the generator itself.
 *
 * That candidate is wrong, and the reason is *when* the tables fill. A routes file
 * is read during a WordPress action: `admin_menu`, `rest_api_init`,
 * `template_redirect`. A view rendering `route('leads.index')` runs inside those
 * same actions, and a redirect runs inside `admin_post`. So the generator is
 * resolved at a moment when the web table may be full and the REST one empty, or
 * the reverse — and a generator that copied the routes into itself at
 * construction would be correct only for whichever domain happened to load first.
 * The failure is quiet: a name that exists resolves to null, and a template falls
 * back to a bare path nobody notices until a link 404s.
 *
 * So the tables are read **through** this object rather than copied from it. The
 * generator holds this registry, and the registry asks the routers on every
 * lookup. A route registered after the generator was built is found anyway.
 *
 * ## Registered by the adapters, so it cannot be forgotten
 *
 * Each adapter hands itself to {@see add()} in its own `flush()`. Nothing else
 * has to remember to do it: a router that has published routes has registered
 * them here, and a router that has not has nothing to contribute yet. That
 * ordering — flush, then lookup — is the same one the routes themselves follow.
 *
 * ## Names are per-domain, and a collision is reported, not merged
 *
 * Two routes may not share a name. The router tolerates it (`Route::name()`
 * explains why), and `route:list` reports it — but a *URL* cannot: `route('leads')`
 * has exactly one answer, and silently picking the first would make a link point
 * at whichever table happened to be registered first. So a duplicate is recorded
 * as an ambiguity and reported when that name is asked for.
 */
final class NamedRoutes
{
    /**
     * Domain label => router. Ordered as registered.
     *
     * @var array<string, RouterInterface>
     */
    private array $routers = [];

    /**
     * Names seen more than once, with the domains that claimed them.
     *
     * @var array<string, list<string>>
     */
    private array $ambiguous = [];

    /**
     * Whether this router's routes have already been recorded.
     *
     * A `flush()` can run more than once — the web hooks each load and flush, and
     * the adapter's own guard is what makes that safe. Registering the same router
     * twice would then look like a name collision and turn an ordinary page load
     * into an "ambiguous route name" error, so the router is recorded once.
     *
     * @var array<string, true>
     */
    private array $registered = [];

    /**
     * Record a router, so its named routes can be looked up.
     *
     * Idempotent per router. Called by the adapters at flush time; a caller that
     * wants to generate URLs before any flush — `route:list`, a test, a command —
     * may call it directly once the routes are loaded.
     */
    public function add(RouterInterface $router): void
    {
        $domain = $this->domainOf($router);

        if (isset($this->registered[$domain])) {
            return;
        }

        $this->registered[$domain] = true;
        $this->routers[$domain] = $router;

        // Two ways a name ends up ambiguous, and both have to be caught here
        // rather than at lookup time.
        //
        //  - **Twice in this router.** The router tolerates a duplicate name, so
        //    the only record of it is the route table itself.
        //  - **Once here, once in a domain already registered.** The check above
        //    catches this only if the *other* router is also scanned, which means
        //    comparing these names against every name recorded so far — not just
        //    against this router's own.
        //
        // The second is the case that matters in practice: `leads` is a perfectly
        // natural name for an admin screen and for a REST endpoint, and the two
        // are declared in different files by different people.
        foreach ($this->duplicateNamesIn($router, $domain) as $name) {
            $this->ambiguous[$name][] = $domain;
        }

        foreach ($this->namesIn($router) as $name) {
            $other = $this->domainDeclaring($name, $domain);

            if ($other !== null) {
                // Both claimants are recorded, not just the second one — the
                // report is what an author acts on, and naming one of the two
                // routes that collide sends them looking for the wrong file. The
                // first is added at the moment the second is found, so the list
                // reads in declaration order.
                if (!in_array($other, $this->ambiguous[$name] ?? [], true)) {
                    $this->ambiguous[$name][] = $other;
                }

                if (!in_array($domain, $this->ambiguous[$name] ?? [], true)) {
                    $this->ambiguous[$name][] = $domain;
                }
            }
        }
    }

    /**
     * The route registered under a name, or null.
     *
     * Searches every registered domain, because a name is chosen by the author and
     * carries no domain of its own — `route('leads.index')` should not require the
     * caller to know that "leads" happens to be a web screen. The first domain to
     * declare it wins, which is only ever observable when a name is duplicated,
     * and that case is reported by {@see ambiguity()}.
     */
    public function find(string $name): ?Route
    {
        foreach ($this->routers as $router) {
            foreach ($router->getRoutes() as $route) {
                if ($route->getName() === $name) {
                    return $route;
                }
            }
        }

        return null;
    }

    /**
     * The domains claiming a name that more than one route declared.
     *
     * @return list<string>
     */
    public function ambiguity(string $name): array
    {
        return $this->ambiguous[$name] ?? [];
    }

    /**
     * Every registered name, for diagnostics.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];

        foreach ($this->routers as $router) {
            foreach ($router->getRoutes() as $route) {
                $name = $route->getName();

                if ($name !== null && !in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * The domain labels currently registered.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        return array_keys($this->routers);
    }

    /**
     * Forget every router. For tests, which build several scenarios in one
     * process against fresh routers.
     */
    public function reset(): void
    {
        $this->routers = [];
        $this->ambiguous = [];
        $this->registered = [];
    }

    /**
     * How a router is named in a collision report.
     *
     * `domain()` is the router's own answer — "web" or "api" — so a project that
     * adds a third adapter gets a sensible label without this class knowing about
     * it. The class name is the fallback, because a label of "" would leave the
     * report saying a name was claimed twice by nothing.
     */
    private function domainOf(RouterInterface $router): string
    {
        if (method_exists($router, 'domain')) {
            $domain = $router->domain();

            if (is_string($domain) && $domain !== '') {
                return $domain;
            }
        }

        return $router::class;
    }

    /**
     * Names this router declares more than once.
     *
     * @return list<string>
     */
    private function duplicateNamesIn(RouterInterface $router, string $domain): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($router->getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null) {
                continue;
            }

            if (isset($seen[$name]) && !in_array($name, $duplicates, true)) {
                $duplicates[] = $name;

                continue;
            }

            $seen[$name] = true;
        }

        return $duplicates;
    }

    /**
     * Every distinct name this router declares.
     *
     * @return list<string>
     */
    private function namesIn(RouterInterface $router): array
    {
        $names = [];

        foreach ($router->getRoutes() as $route) {
            $name = $route->getName();

            if ($name !== null && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The first other domain declaring a name, or null.
     *
     * Comparing against the other domains' tables rather than against an
     * accumulated list of every name is what makes "declared twice" mean declared
     * by two routes: a single router's own names are checked by
     * {@see duplicateNamesIn()}, and counting them again here would report every
     * name as ambiguous.
     */
    private function domainDeclaring(string $name, string $exclude): ?string
    {
        foreach ($this->routers as $other => $router) {
            if ($other === $exclude) {
                continue;
            }

            foreach ($router->getRoutes() as $route) {
                if ($route->getName() === $name) {
                    return $other;
                }
            }
        }

        return null;
    }
}
