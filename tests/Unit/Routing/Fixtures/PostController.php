<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Routing\Attributes\Route;
use Framework\Routing\Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * A controller exercising the attribute-driven registration path.
 *
 * Deliberately includes the awkward cases — a multi-verb attribute, a missing
 * path (defaulting to the method name), an action with a scalar parameter, and
 * a non-public method that must be ignored — so the tests cover the reflection
 * behaviour rather than just the happy path.
 */
final class PostController extends Controller
{
    #[Route('GET', 'posts')]
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(['action' => 'index']);
    }

    #[Route('GET', 'posts/{id}')]
    public function show(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(['id' => $request->get_param('id')]);
    }

    #[Route('POST', 'posts')]
    public function store(WP_REST_Request $request): WP_REST_Response
    {
        return $this->created(['action' => 'store']);
    }

    /**
     * No path given, so the route path defaults to the method name.
     */
    #[Route('GET')]
    public function latest(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(['action' => 'latest']);
    }

    /**
     * A scalar parameter filled from a route placeholder, which is the case
     * that proves request parameters win over container auto-wiring.
     */
    #[Route('GET', 'posts/{id}/meta')]
    public function meta(int $id): WP_REST_Response
    {
        return $this->respond(['id' => $id, 'type' => get_debug_type($id)]);
    }

    /**
     * Middleware declared on the attribute rather than in a routes file.
     */
    #[Route('DELETE', 'posts/{id}', middleware: [AllowMiddleware::class])]
    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        return $this->noContent();
    }

    #[Route('PUT', 'posts/{id}')]
    #[Route('PATCH', 'posts/{id}')]
    public function update(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(['action' => 'update']);
    }

    /**
     * A scalar parameter with no default, so a missing or null value must be
     * reported rather than passed through as null.
     */
    #[Route('GET', 'posts/by-slug/{slug}')]
    public function bySlug(string $slug): WP_REST_Response
    {
        return $this->respond(['slug' => $slug]);
    }

    /**
     * A bool parameter, which is the type PHP coerces most surprisingly: the
     * non-empty string "false" becomes true. Returns both the value and its
     * runtime type so a test can prove the coercion happened before reflection
     * rather than after.
     */
    #[Route('POST', 'posts/publish')]
    public function publish(bool $published, int $qty = 1): WP_REST_Response
    {
        return $this->respond([
            'published' => $published,
            'qty' => $qty,
        ]);
    }

    /**
     * Not public, so it must never be registered as a route.
     */
    #[Route('GET', 'posts/internal')]
    protected function internal(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond([]);
    }

    // The response helpers are protected, so a test cannot call them on the
    // controller directly; these pass-throughs exercise them without widening
    // the framework's own visibility.

    public function ok(): WP_REST_Response
    {
        return $this->respond([]);
    }

    public function made(): WP_REST_Response
    {
        return $this->created([]);
    }

    public function empty(): WP_REST_Response
    {
        return $this->noContent();
    }
}
