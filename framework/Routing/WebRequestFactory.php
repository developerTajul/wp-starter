<?php
declare(strict_types=1);

namespace Framework\Routing;

use Framework\Http\Request;

/**
 * Builds the framework Request a web route is dispatched with.
 *
 * An interface rather than a plain static call for one reason: `Request::capture()`
 * reads the superglobals, and in a PHPUnit process those are empty. A test that
 * wants to dispatch an admin-post handler with a specific body has no way to put
 * one in `$_POST` — `php://input` cannot be faked at all — so the request has to
 * be injectable. Production uses {@see CaptureWebRequestFactory}; tests bind
 * their own, exactly as `HttpRequestBindingTest` does for REST.
 *
 * ## Why a separate type from Request::fromRestRequest()
 *
 * A REST request arrives as a `WP_REST_Request` that WordPress has already
 * parsed, so its parameters are read out of that object. A web request has no
 * such object: WordPress never parsed it, because no web routing mechanism
 * existed for it to parse against. So the request is captured from the
 * superglobals — `$_GET`, `$_POST`, `$_SERVER`, `php://input` — which for an
 * admin-post submission is exactly where the form body lives, and for a
 * `template_redirect` page is where the query string and headers live.
 *
 * URL-matched parameters are then overlaid, so a controller reads a route
 * placeholder the same way in either domain.
 */
interface WebRequestFactory
{
    /**
     * Build the request for one dispatch.
     *
     * @param array<string, string> $urlParams Parameters matched out of the
     *                                          route path, e.g. `['id' => '7']`
     *                                          for `leads/{id}`. Empty for a
     *                                          path with no placeholders.
     */
    public function make(array $urlParams = []): Request;
}
