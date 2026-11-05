<?php
declare(strict_types=1);

namespace Framework\Routing;

/**
 * The lifecycle a route is published into.
 *
 * Three cases, because WordPress offers three genuinely different answers to
 * "when does this run" — and a route's domain decides which hook publishes it,
 * what its `uri` means, and what a controller may return.
 *
 * Kept as an enum rather than three string constants so a typo is a load-time
 * error instead of a route that silently never registers. `RouterInterface::domain()`
 * returns the backing string, which is what `route:list` prints.
 *
 * ## The three, and what each one is for
 *
 *  - **{@see self::Api}** — `rest_api_init`. A JSON endpoint under the REST
 *    namespace. Verb is meaningful, the path is a URL the client requests, and
 *    WordPress owns the response shape.
 *  - **{@see self::Admin}** — `admin_menu` and `admin_post_*`. A wp-admin screen
 *    or a form handler. The `uri` is a *slug*, not a URL: WordPress builds the
 *    real address from `admin.php?page={slug}` after registering the menu.
 *  - **{@see self::Web}** — `template_redirect`. A front-end request matched by
 *    path against the resolved query. This is the one domain WordPress does not
 *    dispatch for you, so the adapter matches it itself.
 */
enum RouteDomain: string
{
    /**
     * A front-end request, published on `template_redirect`.
     */
    case Web = 'web';

    /**
     * A wp-admin screen or form handler, published on `admin_menu` and
     * `admin_post_*`.
     */
    case Admin = 'admin';

    /**
     * A REST endpoint, published on `rest_api_init`.
     */
    case Api = 'api';

    /**
     * The WordPress hook this domain publishes into.
     *
     * Reported by `route:list` so a developer can see at a glance which mechanism
     * a route actually reaches — the one fact that is otherwise invisible until
     * something 404s.
     */
    public function hook(): string
    {
        return match ($this) {
            self::Web => 'template_redirect',
            self::Admin => 'admin_menu',
            self::Api => 'rest_api_init',
        };
    }

    /**
     * A route with a body — POST, PUT, PATCH, DELETE — rather than a plain GET.
     *
     * The distinction matters in the admin domain, where WordPress splits form
     * handlers off onto `admin_post_*`: a GET route there is a *screen* rendered
     * through `admin_menu`, while a POST to the same slug is a *handler* fired
     * through `admin_post_`. One slug, two mechanisms, decided by the verb.
     *
     * @param list<string> $methods
     */
    public static function isMutating(array $methods): bool
    {
        foreach ($methods as $method) {
            if (strtoupper($method) !== 'GET') {
                return true;
            }
        }

        return false;
    }
}
