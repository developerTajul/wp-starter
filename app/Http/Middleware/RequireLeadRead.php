<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Framework\Routing\Middleware\RequireCapability;

/**
 * Rejects the request unless the caller may read other people's contact
 * details.
 *
 * A subclass rather than a parameter, because the framework resolves a REST
 * route's middleware by class name only: `Middleware::class . ':capability'`
 * registers a route whose middleware cannot be resolved. That failure is
 * quiet, since the resolution throws, the adapter converts the throw into a
 * denial, and the endpoint 403s for every caller including administrators.
 *
 * `list_users` is the narrowest core capability covering this read.
 * `manage_options` would also hand integration accounts full site
 * administration; `edit_posts` would not cover a subscriber's address.
 *
 * This guard admits a caller to the endpoint, not to every field of it. The
 * `notes` field is gated separately by
 * {@see \App\Http\Controllers\Api\LeadController::NOTES_CAPABILITY}, because
 * the populations differ. A third field needing its own policy gets its own
 * named capability rather than a wider version of this one.
 */
final class RequireLeadRead extends RequireCapability
{
    public function __construct()
    {
        parent::__construct('list_users');
    }
}
