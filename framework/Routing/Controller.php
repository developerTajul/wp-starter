<?php
declare(strict_types=1);

namespace Framework\Routing;

use Framework\Routing\Attributes\Route as RouteAttribute;
use ReflectionClass;
use ReflectionMethod;
use WP_REST_Response;

/**
 * Base class for REST controllers.
 *
 * A controller does not have to extend this — the router dispatches to any
 * resolvable object — but doing so buys the attribute-driven registration below
 * and the response helpers, which is what keeps route definitions out of a
 * central routes file.
 */
abstract class Controller
{
    /**
     * Register every method on this controller carrying a #[Route] attribute.
     *
     * Routes are added to the given registrar, so the caller controls the path
     * prefix and any middleware shared across the whole controller:
     *
     *     $router->group(['prefix' => 'posts'], function (RouteRegistrar $r) {
     *         $r->controllerFromAttributes(PostController::class);
     *     });
     *
     * @throws Exceptions\RouteRegistrationException
     */
    public function registerRoutes(RouteRegistrar $registrar): void
    {
        $reflection = new ReflectionClass($this);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }

            foreach ($method->getAttributes(RouteAttribute::class) as $attribute) {
                /** @var RouteAttribute $definition */
                $definition = $attribute->newInstance();
                $path = $definition->path !== '' ? $definition->path : $method->getName();

                $registrar->controllerAction(
                    $definition->methods(),
                    $path,
                    static::class,
                    $method->getName(),
                    $definition->middleware,
                    $definition->permissionCallback
                );
            }
        }
    }

    /**
     * Wrap data in an explicit WP_REST_Response.
     *
     * Returning the raw array would work too — WordPress wraps it with a 200 —
     * but a controller that needs any other status or a header has to build the
     * response itself, and mixing the two styles in one class reads as an
     * oversight rather than a decision. Being explicit everywhere avoids that.
     *
     * @param array<mixed>|object $data
     */
    protected function respond(array|object $data, int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response($data, $status);
    }

    /**
     * @param array<mixed>|object $data
     */
    protected function created(array|object $data): WP_REST_Response
    {
        return $this->respond($data, 201);
    }

    protected function noContent(): WP_REST_Response
    {
        return new WP_REST_Response(null, 204);
    }
}
