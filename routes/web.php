<?php
declare(strict_types=1);

/**
 * Web routes for this plugin.
 *
 * Surfaces: `adminPage()` -> admin_menu, `adminAction()` -> admin_post_{action},
 * `page()` -> template_redirect. `get()`/`post()` and the other verbs pick a
 * surface from the verb; the named helpers state it explicitly and win.
 *
 * Middleware runs in the order listed: authenticate, then capability, then
 * nonce, then input sanitizing. Trim before null, since `'   '` only becomes
 * null once trimmed.
 */

use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\DashboardController;
use Framework\Http\Middleware\Authenticate;
use Framework\Http\Middleware\ConvertEmptyStringsToNull;
use Framework\Http\Middleware\SanitizeInput;
use Framework\Http\Middleware\TrimStrings;
use Framework\Support\Facades\Route;

// ---------------------------------------------------------------------------- dashboard

// Home screen of the plugin.
Route::get(DashboardController::class, 'index')
    ->name('dashboard.index')
    ->withOptions([
        'page_title' => 'WP Mini Laravel',
        'menu_title' => 'WP Mini Laravel',
        'capability' => 'manage_options',
        'position'   => 25,
    ]);

// ---------------------------------------------------------------------------- companies

// One group, one screen or handler per use case.
//
// The group carries only the shared guard. It carries no `prefix`: an admin
// screen's slug IS its address, so a path prefix would turn `companies` into
// `companies/companies` and register a menu that nothing can reach. It carries no
// `as` either: the screens and the form handlers are already named on different
// roots (`companies.*` and `company.*`), and a single prefix is the wrong tool
// for two naming schemes.
//
// `nonce:` stays on each handler, and `TrimStrings`/`ConvertEmptyStringsToNull`
// stay where they are: each write route is addressed by its own `admin_post_{action}`
// name and verifies the nonce minted for that action, and the bulk handler takes
// an array of ids rather than scalars, so it strips neither.
Route::group([
    'middleware' => [
        Authenticate::class,
        'capability:' . CompanyController::CAPABILITY,
    ],
], function (): void {
    // List companies.
    Route::get(CompanyController::SCREEN_SLUG, [CompanyController::class, 'index'])
        ->name('companies.index')
        ->withOptions([
            'page_title' => 'Companies',
            'menu_title' => 'Companies',
            'capability' => CompanyController::CAPABILITY,
            'icon'       => 'dashicons-building',
            'position'   => 26,
        ])
        ->middleware([
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
            SanitizeInput::class,
        ]);

    // Add a company
    // public const CREATE_SLUG = 'companies-create';
    Route::get(CompanyController::CREATE_SLUG, [CompanyController::class, 'create'])
        ->name('companies.create')
        ->withOptions([
            'page_title'  => 'Add a company',
            'menu_title'  => 'Add a company',
            'capability'  => CompanyController::CAPABILITY,
            'parent_slug' => CompanyController::SCREEN_SLUG,
        ])
        ->middleware([
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
            SanitizeInput::class,
        ]);

    Route::post('companies/store', [CompanyController::class, 'store'])
        ->name('company.store')
        ->withOptions(['action' => CompanyController::STORE_ACTION])
        ->middleware([
            'nonce:' . CompanyController::STORE_ACTION,
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
            SanitizeInput::class,
        ]);

    Route::post('companies/update', [CompanyController::class, 'update'])
        ->name('company.update')
        ->withOptions(['action' => CompanyController::UPDATE_ACTION])
        ->middleware([
            'nonce:' . CompanyController::UPDATE_ACTION,
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
            SanitizeInput::class,
        ]);

    // Edit a company.
    //
    // A screen of its own rather than a route parameter: an admin screen's slug IS
    // its address, and WordPress picks the screen by matching `?page=` against the
    // slugs it was handed at `admin_menu`. A path like `companies/{id}/edit` would
    // generate `?page=companies%2F5%2Fedit`, which no menu was ever registered
    // under, so the record would be addressed by a URL that resolves to nothing.
    // The id travels as an ordinary query parameter instead, beside the slug.
    Route::get(CompanyController::EDIT_SLUG, [CompanyController::class, 'edit'])
        ->name('companies.edit')
        ->withOptions([
            'page_title'  => 'Edit company',
            'menu_title'  => 'Edit company',
            'capability'  => CompanyController::CAPABILITY,
            'parent_slug' => CompanyController::SCREEN_SLUG,
        ])
        ->middleware([
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
            SanitizeInput::class,
        ]);

    // Delete one company, from the row action on the list.
    //
    // A write, so it is an `admin_post_` action and not a screen: a link that
    // changed stored data on a GET would be triggered by any prefetch, crawler or
    // reload, and WordPress's own row actions post for the same reason. The id
    // arrives as a form field.
    Route::post('companies/delete', [CompanyController::class, 'destroy'])
        ->name('company.destroy')
        ->withOptions(['action' => CompanyController::DESTROY_ACTION])
        ->middleware([
            'nonce:' . CompanyController::DESTROY_ACTION,
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
            SanitizeInput::class,
        ]);

    // Bulk actions from the companies list.
    //
    // The form's `action` field is the route's own action name, so WordPress
    // dispatches the submission to the handler below. `TrimStrings` and
    // `ConvertEmptyStringsToNull` are absent because they act on scalar fields:
    // `company_ids[]` is an array, and the controller narrows every id to a
    // positive integer itself rather than relying on a middleware built for text.
    Route::post('companies/bulk', [CompanyController::class, 'bulkDestroy'])
        ->name('company.bulk')
        ->withOptions(['action' => CompanyController::BULK_ACTION])
        ->middleware([
            'nonce:' . CompanyController::BULK_ACTION,
            SanitizeInput::class,
        ]);
});

// ---------------------------------------------------------------------------- leads

// The lead screen exists as an example; its controller is not wired into web.php
// yet (it lives on the REST surface). Left here as a stub placeholder so the
// section is visible and the route can be wired in the next step.
//
// Route::get(LeadController::SCREEN_SLUG, [LeadController::class, 'index'])
//     ->name('leads.index')
//     ->withOptions([
//         'page_title'  => 'Leads',
//         'menu_title'  => 'Leads',
//         'capability'  => CompanyController::CAPABILITY,
//         'icon'        => 'dashicons-portfolio',
//         'position'    => 27,
//     ])
//     ->middleware([
//         Authenticate::class,
//         'capability:' . CompanyController::CAPABILITY,
//         TrimStrings::class,
//         ConvertEmptyStringsToNull::class,
//         SanitizeInput::class,
//     ]);
