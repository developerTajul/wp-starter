<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Http\Request;
use Framework\Routing\Attributes\Route;
use Framework\Routing\Controller;
use WP_REST_Response;

/**
 * A controller that takes the framework's `Request` in its *constructor*.
 *
 * This is the case that pins the ordering in `Router::dispatch()`: the container
 * must have the REST-hydrated request bound before the controller is resolved,
 * or the constructor captures the superglobal instance and every action on this
 * controller reads stale data for the rest of its life.
 */
final class ConstructorRequestController extends Controller
{
    public function __construct(private readonly Request $request)
    {
    }

    #[Route('GET', 'leads/from-constructor')]
    public function show(): WP_REST_Response
    {
        return $this->respond([
            'id' => $this->request->input('id'),
            'filter' => $this->request->query('filter'),
        ]);
    }
}
