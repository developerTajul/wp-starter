<?php
declare(strict_types=1);

/**
 * Web routes for this plugin.
 *
 * Loaded by App\Providers\RouteServiceProvider, and published onto whichever
 * WordPress mechanism each route's surface implies:
 *
 *   adminPage()   -> admin_menu          wp-admin screens
 *   adminAction() -> admin_post_{action} form submissions
 *   page()        -> template_redirect   front-end pages
 *
 * `get()`/`post()`/`put()`/`delete()` also work here and infer the surface from
 * the verb — a non-GET verb is a form handler, a bare GET is a front-end page.
 * The three named helpers exist for the cases where the verb does not say:
 * a GET route that is an admin screen, or a POST route that is not a form
 * handler, needs the surface stated.
 *
 * Keep this file a map of the surface. Anything that makes a decision belongs in
 * a controller, where it can be resolved from the container, tested on its own,
 * and reused by a second entry point.
 */

use Framework\Routing\Adapters\WordPressRouterAdapter;

/**
 * @param WordPressRouterAdapter $router
 */
return function (WordPressRouterAdapter $router): void {
    // An admin screen. `admin.php?page=wp-mini-laravel` renders whatever the
    // callback returns; the menu title, capability and icon come from options.
    //
    // A screen callback must RETURN a string or a ResponseInterface — an admin
    // page returns rather than exits, so WordPress can render the surrounding
    // chrome around it.
    $router->adminPage(
        'wp-mini-laravel',
        \App\Http\Controllers\Admin\DashboardController::class,
        'index'
    )->name('dashboard')->withOptions([
        'page_title' => 'WP Mini Laravel',
        'menu_title' => 'Mini Laravel',
        'capability' => 'manage_options',
        'icon'       => 'dashicons-rest-api',
        'position'   => 58,
    ]);
};
