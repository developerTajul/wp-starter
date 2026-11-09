<?php
declare(strict_types=1);

namespace Framework\Routing\Sources;

use Framework\Routing\ParameterSource;
use WP_REST_Request;

/**
 * Reads values out of the `WP_REST_Request` WordPress dispatched.
 *
 * Reads through `has_param()`/`get_param()` rather than reaching into the
 * parameter array, so a controller sees the same value WordPress itself would
 * return for that name - with the same source precedence (JSON, body, query,
 * URL) and the same JSON decoding applied to a raw body.
 */
final class RestParameterSource implements ParameterSource
{
    public function __construct(private readonly WP_REST_Request $request)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function routeParameters(): array
    {
        $params = $this->request->get_url_params();

        return is_array($params) ? $params : [];
    }

    public function value(string $name): mixed
    {
        return $this->request->has_param($name) ? $this->request->get_param($name) : null;
    }
}
