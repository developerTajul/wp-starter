<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Framework\Http\Contracts\ResponseInterface;
use Framework\Support\ViewRenderer;

/**
 * The plugin's admin screen.
 *
 * An admin screen's callback returns rather than exits: WordPress renders the
 * sidebar, toolbar and notices around whatever it produced, so a page route
 * that called `exit` would emit a bare fragment. Front-end routes differ,
 * since `template_redirect` has to be terminated.
 *
 * Does not extend `Framework\Routing\Controller`, whose response helpers build
 * `WP_REST_Response` objects that the web dispatch path emits nothing for. The
 * dispatcher resolves this class from the container and calls one public method.
 */
final class DashboardController
{
    public function __construct(private readonly ViewRenderer $views)
    {
    }

    /**
     * Render the dashboard screen.
     *
     * The child view is rendered to a string and passed to the layout as
     * `$content`; returning the `ViewResponse` leaves status and headers to
     * the view layer.
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
     * The providers the router is configured with.
     *
     * Read from config so the screen reports what the application booted
     * rather than a hardcoded list.
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
