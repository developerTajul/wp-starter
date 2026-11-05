<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Routing\Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A controller with a constructor dependency, which is the case that proves the
 * container — not `new` — is what constructs a controller.
 */
final class ReportController extends Controller
{
    public function __construct(private readonly ReportSource $source)
    {
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(['source' => $this->source->name()]);
    }

    /**
     * The request and a route parameter in one signature: the request arrives by
     * type, the parameter by name.
     */
    public function show(WP_REST_Request $request, string $id): WP_REST_Response
    {
        return $this->respond(['id' => $id, 'source' => $this->source->name()]);
    }
}
