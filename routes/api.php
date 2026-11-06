<?php
declare(strict_types=1);

use App\Http\Controllers\Api\LeadController;
use App\Http\Middleware\RequireLeadRead;
use Framework\Support\Facades\Route;

/**
 * REST routes.
 *
 * Loaded by Framework\Routing\RouteServiceProvider on `rest_api_init`.
 * Reached at /wp-json/{config('app.rest_namespace')}/...
 */

// Health check
Route::get('ping', static fn (): array => ['pong' => true])->name('ping');

// Leads
Route::get('leads', [LeadController::class, 'index'])
    ->name('api.leads.index')
    ->middleware(RequireLeadRead::class);

Route::get('leads/{id}', [LeadController::class, 'show'])
    ->name('api.leads.show')
    ->middleware(RequireLeadRead::class);
