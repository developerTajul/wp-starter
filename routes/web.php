<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\DashboardController;
use Framework\Support\Facades\Route;

// ---------------------------------------------------------------------------- dashboard

Route::get('wp-mini-laravel', [DashboardController::class, 'index'])
    ->name('dashboard.index')
    ->middleware(['auth', 'capability:manage_options'])
    ->withOptions([
        'page_title' => 'WP Mini Laravel',
        'menu_title' => 'WP Mini Laravel',
        'capability' => 'manage_options',
        'position'   => 25,
    ]);

// ---------------------------------------------------------------------------- companies

Route::group([
    'middleware' => [
        'auth',
        'capability:' . CompanyController::CAPABILITY,
        'sanitize',
    ],
], function (): void {
    
    Route::post('companies/bulk', [CompanyController::class, 'bulkDestroy'])
        ->name('company.bulk')
        ->withOptions(['action' => CompanyController::BULK_ACTION])
        ->middleware(['nonce:' . CompanyController::BULK_ACTION]);

    Route::group([
        'middleware' => ['trim', 'null'],
    ], function (): void {
        
        Route::get(CompanyController::SCREEN_SLUG, [CompanyController::class, 'index'])
            ->name('companies.index')
            ->withOptions([
                'page_title' => 'Companies',
                'menu_title' => 'Companies',
                'capability' => CompanyController::CAPABILITY,
                'icon'       => 'dashicons-building',
                'position'   => 26,
            ]);

        Route::get(CompanyController::CREATE_SLUG, [CompanyController::class, 'create'])
            ->name('companies.create')
            ->withOptions([
                'page_title'  => 'Add a company',
                'menu_title'  => 'Add a company',
                'capability'  => CompanyController::CAPABILITY,
                'parent_slug' => CompanyController::SCREEN_SLUG,
            ]);

        Route::post('companies/store', [CompanyController::class, 'store'])
            ->name('company.store')
            ->withOptions(['action' => CompanyController::STORE_ACTION])
            ->middleware(['nonce:' . CompanyController::STORE_ACTION]);

        Route::post('companies/update', [CompanyController::class, 'update'])
            ->name('company.update')
            ->withOptions(['action' => CompanyController::UPDATE_ACTION])
            ->middleware(['nonce:' . CompanyController::UPDATE_ACTION]);

        Route::get(CompanyController::EDIT_SLUG, [CompanyController::class, 'edit'])
            ->name('companies.edit')
            ->withOptions([
                'page_title'  => 'Edit company',
                'menu_title'  => 'Edit company',
                'capability'  => CompanyController::CAPABILITY
            ]);

        Route::post('companies/delete', [CompanyController::class, 'destroy'])
            ->name('company.destroy')
            ->withOptions(['action' => CompanyController::DESTROY_ACTION])
            ->middleware(['nonce:' . CompanyController::DESTROY_ACTION]);
    });
});