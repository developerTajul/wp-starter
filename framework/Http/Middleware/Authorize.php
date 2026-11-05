<?php
declare(strict_types=1);

namespace Framework\Http\Middleware;

use Closure;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;

/**
 * Rejects the request unless the current user holds every required capability.
 *
 * Sits after {@see Authenticate} in a stack — on its own it will deny an
 * anonymous request, which is correct but reports it as a permission failure
 * rather than a missing login.
 *
 *     $router->post('leads/{id}/assign', LeadController::class, 'assign')
 *         ->middleware(Authenticate::class, Authorize::class);
 *
 *     final class RequireEditPosts extends Authorize
 *     {
 *         public function __construct()
 *         {
 *             parent::__construct('edit_posts');
 *         }
 *     }
 *
 * ## Capabilities, not roles
 *
 * `current_user_can()` is the only check here. Roles are not accepted, because a
 * capability check is what survives a site installing a role-editing plugin or
 * a membership plugin that grants `edit_others_posts` without granting `editor`.
 * The same reason WordPress core moved off `current_user_can('level_10')`.
 *
 * ## Multiple capabilities
 *
 * Every capability listed must be held. This is not as obvious as it sounds:
 * `authorize() => 'edit_posts'` on a REST route accepts an *array* of
 * capabilities and grants access if **any** is held, so passing a list to this
 * middleware expecting OR semantics would silently be stricter than intended.
 * All-of is the safe default — a caller who meant "any of these" can express it
 * with a subclass or a custom capability.
 */
class Authorize implements Middleware
{
    /**
     * @var list<string>
     */
    private readonly array $capabilities;

    /**
     * @param string|array<int, string> $capability One capability, or several
     *                                              that must all be held.
     */
    public function __construct(string|array $capability = 'manage_options')
    {
        $this->capabilities = array_values(array_filter(
            array_map('strval', (array) $capability),
            static fn (string $cap): bool => $cap !== ''
        ));
    }

    public function handle(Request $request, Closure $next): ResponseInterface
    {
        if ($this->isAuthorized()) {
            return $next($request);
        }

        // Always JSON, whether or not the client asked for it. A permission
        // failure is answered the same way on every entry point, so a caller
        // switching between a form post and a headless call sees one shape. The
        // status code is what varies, and it is decided by `status()`.
        return new JsonResponse(
            ['error' => 'forbidden', 'message' => 'You are not allowed to perform this action.'],
            ['status_code' => $this->status()]
        );
    }

    /**
     * The status a denial carries.
     *
     * 401 for an anonymous caller and 403 for an authenticated one, which is
     * exactly what `rest_authorization_required_code()` computes and what
     * {@see \Framework\Routing\Middleware\RequireCapability} reports, so the same
     * denial looks the same on both entry points. Inlined rather than delegated
     * because that function must exist for WordPress to have dispatched the
     * request, and the two lines are cheaper than the `function_exists()` guard
     * a call to it would need here.
     */
    private function status(): int
    {
        return function_exists('is_user_logged_in') && is_user_logged_in() ? 403 : 401;
    }

    /**
     * Whether the current user holds every required capability.
     */
    private function isAuthorized(): bool
    {
        // No capabilities configured means nothing was required. That is a
        // misconfiguration rather than an allow — but denying would make the
        // mistake invisible, so it is reported into the log and treated as open,
        // matching how the container treats a parameter it cannot resolve.
        if ($this->capabilities === []) {
            $this->reportMisconfiguration();

            return true;
        }

        foreach ($this->capabilities as $capability) {
            if (!$this->holds($capability)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the current user holds one capability.
     *
     * Separated from the loop so a subclass has a seam to override — for a
     * capability this middleware should always grant, or for a test that needs
     * to answer differently per capability without reaching into WordPress.
     */
    protected function holds(string $capability): bool
    {
        if (!function_exists('current_user_can')) {
            // Failing closed, for the same reason the nonce middleware does: an
            // authorization check that cannot run has authorized nothing.
            return false;
        }

        return current_user_can($capability);
    }

    private function reportMisconfiguration(): void
    {
        if (function_exists('error_log')) {
            error_log(
                'WP Mini Laravel: Authorize middleware was given no capabilities, '
                . 'so it is allowing every request. Pass a capability to the constructor.'
            );
        }
    }
}
