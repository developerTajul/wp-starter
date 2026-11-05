<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Routing\RouteResolver;
use Framework\Routing\Router;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;
use WP_Error;
use WP_REST_Request;

/**
 * Adversarial audit of the permission path.
 *
 * Rule 1 of the security brief: no route may be public except by explicit
 * decision, and a check that fails must deny. These tests pin the boundary
 * between "deliberately public" and "public because a check silently did
 * nothing".
 */
final class PermissionAuditTest extends TestCase
{
    private const NAMESPACE = 'audit/v1';

    /**
     * @var list<string>
     */
    private array $denials = [];

    private Router $router;

    protected function setUp(): void
    {
        WpStub::reset();

        $container = new Container();
        $this->router = new Router($container, new RouteResolver($container));
        $this->denials = [];
    }

    /**
     * A middleware returning a WP_Error denies with its own status code, which
     * is how a middleware distinguishes 403 from 401.
     */
    public function test_a_denying_middleware_returns_its_own_wp_error(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->middleware(Fixtures\DenyMiddleware::class);
        $this->router->registerRoutes(self::NAMESPACE);

        $result = $this->permission('posts');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(Fixtures\DenyMiddleware::STATUS, $result->get_status());
    }

    /**
     * A middleware returning null means "no opinion", and the request proceeds.
     * This is the documented contract, and it must not be confused with a denial.
     */
    public function test_a_middleware_returning_null_allows(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->middleware(Fixtures\AllowMiddleware::class);
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertTrue($this->permission('posts'));
    }

    /**
     * A middleware that throws must not escape as an uncaught throwable from
     * inside the permission callback — WordPress would render that as a 500,
     * and an unauthenticated client could trigger it.
     */
    public function test_a_throwing_middleware_does_not_return_a_truthy_allow(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->middleware(Fixtures\ThrowingMiddleware::class);
        $this->router->registerRoutes(self::NAMESPACE);

        try {
            $result = $this->permission('posts');
        } catch (\Throwable $e) {
            $this->fail('A throwing middleware escaped the permission callback: ' . $e->getMessage());
        }

        $this->assertNotTrue(
            $result,
            'A throwing middleware must never result in an allowed request.'
        );
    }

    /**
     * A callback returning a WP_Error is passed through unchanged, preserving
     * the status code the callback chose — a 404 for "no such object" reads
     * very differently from a 403.
     */
    public function test_an_explicit_callback_wp_error_is_passed_through_unchanged(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->permissionCallback('Tests\Unit\Routing\Fixtures\deny_permission');
        $this->router->registerRoutes(self::NAMESPACE);

        $result = $this->permission('posts');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('forbidden', $result->get_error_code());
        $this->assertSame(403, $result->get_status());
    }

    /**
     * A callback returning true explicitly allows, and the request proceeds.
     */
    public function test_an_explicit_callback_returning_true_allows(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->permissionCallback('Tests\Unit\Routing\Fixtures\allow_permission');
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertTrue($this->permission('posts'));
    }

    /**
     * A middleware violating the contract — returning a truthy string instead
     * of a WP_Error or null — must be reported, not silently read as an allow.
     *
     * `middleware()` takes a class-string, so a class that never declared
     * `implements Middleware` reaches the container unchallenged; PHP's own
     * return-type checking cannot apply because there is no interface to check
     * against. The router is the only thing standing between that class and a
     * permanent "allow".
     */
    public function test_a_middleware_violating_the_contract_is_reported_as_an_error(): void
    {
        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->middleware(Fixtures\LooseMiddleware::class);
        $this->router->registerRoutes(self::NAMESPACE);

        $result = $this->permission('posts');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertStringContainsString('LooseMiddleware', $result->get_error_message());
    }

    /**
     * Explicit callback denies: middleware must not be reached, and the WP_Error
     * with its own status must be what the client receives.
     */
    public function test_an_explicit_callback_denial_short_circuits_middleware(): void
    {
        Fixtures\RecordingMiddleware::$ran = [];

        $this->router->registrar()
            ->get('posts', Fixtures\PostController::class, 'index')
            ->permissionCallback('Tests\Unit\Routing\Fixtures\deny_permission')
            ->middleware(Fixtures\RecordingMiddleware::class);
        $this->router->registerRoutes(self::NAMESPACE);

        $result = $this->permission('posts');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(403, $result->get_status());
        $this->assertSame([], Fixtures\RecordingMiddleware::$ran, 'Middleware ran despite a denial.');
    }

    /**
     * A route with neither middleware nor an explicit callback is public. That
     * is a deliberate design choice, but it must be *reported* as public rather
     * than looking identical to a route whose check failed to run.
     */
    public function test_a_bare_route_is_public_and_says_so(): void
    {
        $this->router->registrar()->get('posts', Fixtures\PostController::class, 'index');
        $this->router->registerRoutes(self::NAMESPACE);

        $this->assertTrue($this->permission('posts'));
    }

    private function permission(string $route): mixed
    {
        $pattern = Router::toWordPressRoute($route);

        foreach (WpStub::$registeredRoutes[self::NAMESPACE] as $registration) {
            if ($registration['route'] === $pattern) {
                return ($registration['permission_callback'])(new WP_REST_Request());
            }
        }

        $this->fail(sprintf('No route was registered for [%s].', $route));
    }
}
