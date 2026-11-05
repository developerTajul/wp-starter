<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Http\Request;
use Framework\Routing\Attributes\Route;
use Framework\Routing\Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Controllers that type-hint the framework's own `Request`.
 *
 * The point of this fixture is the *type hint*: a `Request $request` parameter
 * cannot be satisfied by the native `WP_REST_Request` the router is called with,
 * so it can only be filled from the container binding. If the router does not
 * bind one, the container resolves the request captured from the superglobals at
 * plugin load — which carries none of this request's data, and would answer
 * every assertion below with null.
 */
final class HttpRequestController extends Controller
{
    #[Route('GET', 'leads/{id}')]
    public function show(Request $request): WP_REST_Response
    {
        return $this->respond([
            'id' => $request->input('id'),
            'filter' => $request->query('filter'),
        ]);
    }

    /**
     * A JSON body, which is the case the superglobal capture cannot see on a REST
     * request: WordPress has already consumed `php://input`, so the captured
     * instance would find nothing.
     */
    #[Route('POST', 'leads')]
    public function store(Request $request): WP_REST_Response
    {
        return $this->respond([
            'email' => $request->input('email'),
            // Read as an array, because `input()` keys are exact rather than
            // dot-paths — the nested document arrives whole.
            'plan' => $request->input('meta')['plan'] ?? null,
            'isJson' => $request->isJson(),
        ]);
    }

    /**
     * Both request objects side by side, since the constraint is that a
     * controller hinting the native one keeps working.
     */
    #[Route('GET', 'leads/{id}/both')]
    public function both(Request $request, WP_REST_Request $native): WP_REST_Response
    {
        return $this->respond([
            'framework' => $request->input('id'),
            'native' => $native->get_param('id'),
        ]);
    }
}
