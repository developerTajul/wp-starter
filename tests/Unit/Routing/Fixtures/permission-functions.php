<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use WP_Error;
use WP_REST_Request;

/**
 * Plain-function permission callbacks, for the permissionCallback() path.
 *
 * Declared here rather than in a test class because the container resolves a
 * permission callback by name through Container::call(), which reflects on a
 * plain function — the same shape WordPress uses for a named callback.
 *
 * Required explicitly from the test file that uses them: PHPUnit only loads
 * class files by name, and these are functions.
 */

if (!function_exists(__NAMESPACE__ . '\allow_permission')) {
    function allow_permission(WP_REST_Request $request): bool
    {
        return true;
    }
}

if (!function_exists(__NAMESPACE__ . '\deny_permission')) {
    function deny_permission(WP_REST_Request $request): WP_Error
    {
        return new WP_Error('forbidden', 'Not allowed.', ['status' => 403]);
    }
}

if (!function_exists(__NAMESPACE__ . '\null_permission')) {
    /**
     * The canonical "deny" idiom in WordPress permission callbacks. WordPress
     * denies on null, so the framework must pass it through rather than
     * treating it as "no opinion" and allowing the request.
     */
    function null_permission(WP_REST_Request $request): ?bool
    {
        return null;
    }
}

if (!function_exists(__NAMESPACE__ . '\string_permission')) {
    /**
     * Returns a truthy non-boolean, which WordPress treats as an allow. Pinned
     * so the framework's pass-through behaviour is explicit rather than implied.
     *
     * @return string
     */
    function string_permission(WP_REST_Request $request)
    {
        return 'yes';
    }
}
