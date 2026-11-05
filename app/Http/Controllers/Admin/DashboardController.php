<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

/**
 * The plugin's admin screen.
 *
 * ## Reading on an admin screen
 *
 * An admin screen's callback *returns*; it does not exit. WordPress renders the
 * sidebar, the toolbar and the notices around whatever the callback produced, so
 * a page route that called `exit` here would produce a bare fragment with no
 * admin chrome. That is the one behavioural difference from a front-end route,
 * where `template_redirect` has to be terminated.
 *
 * ## What a controller may return
 *
 * A string, which the dispatcher wraps in an HtmlResponse, or any
 * `Framework\Http\Contracts\ResponseInterface` — a `ViewResponse` for a rendered
 * template, a `RedirectResponse` for a form handler that finished. Returning an
 * array is a REST idiom and is refused with a message naming the route, because
 * the alternative is the literal text "Array" on the page.
 *
 * Nothing here calls a WordPress function. The screen renders from data the
 * framework handed it, which is what keeps the controller testable without the
 * CMS loaded.
 *
 * ## Why this does not extend Framework\Routing\Controller
 *
 * That base class is REST-shaped — its response helpers build `WP_REST_Response`
 * objects, which only the REST server renders and which would emit nothing at all
 * through the web dispatch path. A web controller that extended it would inherit
 * three helpers it must not call. A controller is not required to extend
 * anything; the dispatcher resolves it from the container and calls one public
 * method.
 */
final class DashboardController
{
    /**
     * Render the dashboard screen.
     *
     * Returning a string rather than a rendered view for now: this project has no
     * view layer yet, and a screen that proves the whole path — route, admin_menu
     * registration, container resolution, dispatch, response — is more useful
     * than one that only proves the view renderer works.
     */
    public function index(): string
    {
        return '<div class="wrap">'
            . '<h1>WP Mini Laravel</h1>'
            . '<p>Routing is wired up. This screen was registered by '
            . '<code>Framework\\Routing\\Adapters\\WordPressRouterAdapter</code> on '
            . '<code>admin_menu</code>, and dispatched through the HTTP pipeline.</p>'
            . '<p>Run <code>wp wp_mini route:list</code> to see every registered route.</p>'
            . '</div>';
    }
}
