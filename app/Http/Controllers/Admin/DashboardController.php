<?php
declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Framework\Http\Contracts\ResponseInterface;
use Framework\Support\ViewRenderer;

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
 * ## Why the renderer is injected rather than called as a helper
 *
 * `view()` is available and returns the same thing, but a type-hint makes the
 * dependency visible in the signature and lets a test hand in a renderer pointed
 * at fixture views without touching the application's own. The helper is the
 * convenience; the injection is the seam.
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
    public function __construct(private readonly ViewRenderer $views)
    {
    }

    /**
     * Render the dashboard screen as a response.
     *
     * Returns the `ViewResponse` rather than a string, so the status and headers
     * are the view layer's to decide rather than the dispatcher's. The body is
     * handed to the layout as `$content`, which is how composition works without
     * a template language — render the child to a string, pass it inward.
     *
     * Nothing here calls a WordPress function. The screen renders from data the
     * framework handed it, which is what keeps the controller testable without
     * the CMS loaded.
     */
    public function index(): ResponseInterface
    {
        return $this->views->make('layout', [
            'title' => 'WP Mini Laravel',
            'content' => $this->views->render('dashboard', [
                'routesCommand' => 'wp wp_mini route:list',
                'providers' => $this->providers(),
            ]),
        ]);
    }

    /**
     * The providers the router is currently configured with.
     *
     * Read from config rather than listed here, so the screen reports what the
     * application actually booted instead of what someone remembered to update —
     * a distinction that matters the moment a provider is commented out to debug
     * something and the screen keeps claiming it is registered.
     *
     * @return list<string>
     */
    private function providers(): array
    {
        $providers = config('app.providers', []);

        return is_array($providers)
            ? array_values(array_map('strval', $providers))
            : [];
    }
}
