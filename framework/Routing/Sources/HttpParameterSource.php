<?php
declare(strict_types=1);

namespace Framework\Routing\Sources;

use Framework\Http\Request;
use Framework\Routing\ParameterSource;

/**
 * Reads values out of a `Framework\Http\Request`.
 *
 * Two differences from {@see RestParameterSource}, both deliberate:
 *
 *  - **Route parameters come from the request's input rather than a separate
 *    map.** A web request is built by {@see \Framework\Routing\WebRequestFactory},
 *    which folds the matched placeholders over the body and query layers — so by
 *    the time the request exists, the placeholder is already the value at the
 *    front of `input()` and there is no second map to consult. Returning the
 *    placeholders means reading them back out, which keeps the binder's
 *    placeholder-first rule meaningful in this domain too.
 *  - **`input()` rather than `query()` or `post()`.** `input()` is the accessor
 *    that consults body then query, which is what a form handler on
 *    `admin_post_*` needs: an admin form submits POST, a filter form submits GET,
 *    and a controller should not have to know which.
 */
final class HttpParameterSource implements ParameterSource
{
    /**
     * @param array<string, mixed> $routeParameters
     */
    public function __construct(
        private readonly Request $request,
        private readonly array $routeParameters = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function routeParameters(): array
    {
        // Returned as given, and deliberately *not* read back through the request.
        // Reading back looks harmless — the values came from the request — and it
        // silently destroys the precedence this map exists to express: `input()`
        // consults the body before the query, so a form posting `id=999` to
        // `/leads/123` would report `999` here, the binder would prefer it over
        // nothing, and the client would have chosen which record the controller
        // acted on. The map is the record of what the URL matched; the request's
        // own bags are not.
        return $this->routeParameters;
    }

    public function value(string $name): mixed
    {
        return $this->request->input($name);
    }
}
