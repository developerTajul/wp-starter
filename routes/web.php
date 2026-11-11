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
use App\Http\Controllers\Admin\LeadController;
use Framework\Http\Middleware\Authenticate;
use Framework\Http\Middleware\ConvertEmptyStringsToNull;
use Framework\Http\Middleware\SanitizeInput;
use Framework\Http\Middleware\TrimStrings;
use Framework\Support\Facades\Route;

// Companies list
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
        Authenticate::class,
        'capability:' . CompanyController::CAPABILITY,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
        SanitizeInput::class,
    ]);

// Add a company

Route::get(CompanyController::CREATE_SLUG, [CompanyController::class, 'create'])
    ->name('companies.create')
    ->withOptions([
        'page_title'  => 'Add a company',
        'menu_title'  => 'Add a company',
        'capability'  => CompanyController::CAPABILITY,
        'parent_slug' => CompanyController::SCREEN_SLUG,
    ])
    ->middleware([
        Authenticate::class,
        'capability:' . CompanyController::CAPABILITY,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
        SanitizeInput::class,
    ]);


Route::post('companies/store', [CompanyController::class, 'store'])
    ->name('company.store')
    ->withOptions(['action' => CompanyController::STORE_ACTION])
    ->middleware([
        Authenticate::class,
        'capability:' . CompanyController::CAPABILITY,
        'nonce:' . CompanyController::STORE_ACTION,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
        SanitizeInput::class,
    ]);

Route::post('companies/update', [CompanyController::class, 'update'])
    ->name('company.update')
    ->withOptions(['action' => CompanyController::UPDATE_ACTION])
    ->middleware([
        Authenticate::class,
        'capability:' . CompanyController::CAPABILITY,
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
        Authenticate::class,
        'capability:' . CompanyController::CAPABILITY,
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
        Authenticate::class,
        'capability:' . CompanyController::CAPABILITY,
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
        Authenticate::class,
        'capability:' . CompanyController::CAPABILITY,
        'nonce:' . CompanyController::BULK_ACTION,
        SanitizeInput::class,
    ]);
