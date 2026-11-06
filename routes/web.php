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
 *
 * ## Middleware is named by alias where the framework has one
 *
 * `'capability'` and `'nonce'` are registered in
 * {@see \Framework\Http\Middleware\MiddlewareAliases}, which is what keeps this
 * file readable: the policy is visible next to the route, and the class that
 * implements it is one indirection away rather than forty characters of
 * namespace in the middle of a declaration.
 *
 * The order on the store route below is a statement of intent rather than an
 * accident:
 *
 *  1. `Authenticate` — establishes *who* is asking, before anything asks whether
 *     they may. A capability check with no current user denies everyone, and
 *     reports it as a permission failure rather than a missing login. Named as a
 *     class rather than an alias because it takes no parameters — an alias would
 *     buy nothing and hide which class is meant.
 *  2. `capability:...` — may this user write a lead at all?
 *  3. `nonce:...` — did this request come from a form this site rendered? A nonce
 *     proves provenance, not permission, which is why it is a separate layer from
 *     (and after) the capability check.
 *  4. `TrimStrings`, `ConvertEmptyStringsToNull`, `SanitizeInput` — the input is
 *     cleaned only once the request is known to be legitimate, so a rejected
 *     request does no work. Trim before null: `'   '` is only `''` — and
 *     therefore only null — if it was trimmed first.
 *
 * The capability and the nonce action are read from {@see LeadController}'s
 * constants rather than written as literals, because the controller mints the
 * nonce and renders the form that submits to it — one string, three readers.
 */

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LeadController;
use Framework\Http\Middleware\Authenticate;
use Framework\Http\Middleware\ConvertEmptyStringsToNull;
use Framework\Http\Middleware\SanitizeInput;
use Framework\Http\Middleware\TrimStrings;
use Framework\Support\Facades\Route;

// Dashboard
Route::adminPage(
    'wp-mini-laravel',
    DashboardController::class,
    'index'
)
    ->name('dashboard')
    ->withOptions([
        'page_title' => 'WP Mini Laravel',
        'menu_title' => 'Mini Laravel',
        'capability' => 'manage_options',
        'icon'       => 'dashicons-rest-api',
        'position'   => 58,
    ]);

Route::group(['middleware' => [Authenticate::class]], function (): void {
    // Leads list
    Route::adminPage(LeadController::SCREEN_SLUG, LeadController::class, 'index')
        ->name('leads.index')
        ->withOptions([
            'page_title' => 'Leads',
            'menu_title' => 'Leads',
            'capability' => LeadController::CAPABILITY,
            'icon'       => 'dashicons-email',
            'position'   => 26,
        ]);

    // Add a lead
    Route::adminPage(LeadController::CREATE_SLUG, LeadController::class, 'create')
        ->name('leads.create')
        ->withOptions([
            'page_title'  => 'Add a lead',
            'menu_title'  => 'Add a lead',
            'capability'  => LeadController::CAPABILITY,
            'parent_slug' => LeadController::SCREEN_SLUG,
        ]);
});

// Store a lead
Route::post('leads/store', [LeadController::class, 'store'])
    ->name('store')
    ->withOptions(['action' => LeadController::STORE_ACTION])
    ->middleware([
        Authenticate::class,
        'capability:' . LeadController::CAPABILITY,
        'nonce:' . LeadController::STORE_ACTION,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
        SanitizeInput::class,
    ]);


    // Route::get('/companies', [CompanyController::class, 'index'])
    //     ->name('companies.index')
    //     ->withOptions([
    //         'page_title' => 'Companies',
    //         'menu_title' => 'Companies',
    //         'capability' => LeadController::CAPABILITY,
    //         'icon'       => 'dashicons-email',
    //         'position'   => 26,
    //     ])
    //     ->middleware([
    //         Authenticate::class,
    //         'capability:' . LeadController::CAPABILITY,
    //         'nonce:' . LeadController::STORE_ACTION,
    //         TrimStrings::class,
    //         ConvertEmptyStringsToNull::class,
    //         SanitizeInput::class,
    //     ]);
