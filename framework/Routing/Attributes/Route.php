<?php
declare(strict_types=1);

namespace Framework\Routing\Attributes;

use Attribute;

/**
 * Declares a controller method as a REST route.
 *
 * Reads as metadata but has a runtime job: {@see \Framework\Routing\Controller::registerRoutes()}
 * reflects over the controller's methods and registers one route per attribute.
 * That keeps the method, its HTTP verb, its path and its middleware in one place
 * instead of split across a routes file and a controller.
 *
 * @example
 *   use Framework\Routing\Middleware\RequireCapability;
 *
 *   #[Route('GET', 'posts')]
 *   public function index(WP_REST_Request $request): WP_REST_Response
 *
 *   #[Route('DELETE', 'posts/{id:\d+}', middleware: [RequireCapability::class])]
 *   public function destroy(WP_REST_Request $request): WP_REST_Response
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Route
{
    /**
     * @param string|array<int, string>                  $method
     * @param array<int, class-string<Contracts\Middleware>> $middleware
     */
    public function __construct(
        public readonly string|array $method,
        public readonly string $path = '',
        public readonly array $middleware = [],
        public readonly ?string $permissionCallback = null
    ) {
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return array_values((array) $this->method);
    }
}
