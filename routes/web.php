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
