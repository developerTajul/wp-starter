<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Framework\Routing\Middleware\RequireCapability;

/**
 * Rejects the request unless the caller may read other people's contact details.
 *
 * ## Why this is a subclass and not a parameter
 *
 * The framework resolves a REST route's middleware by **class name only** — the
 * route stores a string and the container resolves that string. There is no
 * `Middleware::class . ':capability'` syntax, and writing one registers a route
 * whose middleware cannot be resolved at all. That failure is quiet in the worst
 * way: the resolution throws, the adapter converts the throw into a denial, and
 * the endpoint 403s for *every* caller including administrators. It looks like a
 * permissions bug in the capability check rather than a typo in the route file.
 *
 * So the capability goes in a constructor, and the route names a class that
 * exists — which is checkable by reading the route file.
 *
 * ## Why `list_users`
 *
 * The narrowest core capability that covers "may see other people's contact
 * details". `manage_options` would work and would also hand every integration
 * account full site administration; `edit_posts` would not cover reading a
 * subscriber's address. The point of naming a capability is to pick the smallest
 * one that is actually sufficient, not the one that is definitely enough.
 *
 * ## What this guard does *not* cover
 *
 * Admitting a caller to the endpoint is not the same as admitting them to every
 * field of it. The `notes` field is gated separately, by
 * {@see \App\Http\Controllers\Api\LeadController::NOTES_CAPABILITY} — a
 * capability this class deliberately does not ask for, because the populations
 * genuinely differ: an integration account has a real reason to hold
 * `list_users` and none to read internal sales commentary.
 *
 * That split is intentional, and it must stay *visible*. An earlier version had
 * this class ask for `list_users` while the controller asked for
 * `edit_others_posts` — two guards answering one question, disagreeing, and
 * producing a `notes` key that was absent for authorized callers with no error
 * to explain it. If a third field ever needs its own policy, give it its own
 * named capability rather than widening this one.
 */
final class RequireLeadRead extends RequireCapability
{
    public function __construct()
    {
        parent::__construct('list_users');
    }
}
