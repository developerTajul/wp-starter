<?php
declare(strict_types=1);

namespace Tests\Unit\Http;

use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use Framework\Http\HttpServiceProvider;
use Framework\Http\Middleware\Pipeline;
use Framework\Http\Request;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

/**
 * The provider's job is one binding, and the binding has a subtlety worth
 * pinning: the request must be *the* request — shared, not rebuilt per resolve.
 * A service that type-hints Request and a middleware that reads it must see the
 * same payload the client sent, not two captures of the superglobals taken at
 * two different moments.
 */
final class HttpProviderTest extends TestCase
{
    protected function setUp(): void
    {
        WpStub::reset();
    }

    public function test_it_binds_a_shared_request(): void
    {
        $container = $this->container();
        (new HttpServiceProvider($container))->register();

        $this->assertTrue($container->bound(Request::class));
        $this->assertSame(
            $container->resolve(Request::class),
            $container->resolve(Request::class),
            'The request must be shared, not recaptured per resolve.'
        );
    }

    public function test_it_binds_a_pipeline_that_resolves(): void
    {
        $container = $this->container();
        (new HttpServiceProvider($container))->register();

        $this->assertInstanceOf(Pipeline::class, $container->resolve(Pipeline::class));
    }

    /**
     * A pipeline is per-use: two resolvers must not share one, or configuring a
     * second stack would silently reconfigure the first.
     */
    public function test_each_pipeline_resolution_is_a_fresh_instance(): void
    {
        $container = $this->container();
        (new HttpServiceProvider($container))->register();

        $this->assertNotSame(
            $container->resolve(Pipeline::class),
            $container->resolve(Pipeline::class)
        );
    }

    /**
     * The provider captures the superglobals, and capture() is the only place in
     * the framework allowed to read them — so this is also the test that the
     * production entry point produces a usable Request.
     */
    public function test_the_bound_request_carries_the_query_string(): void
    {
        $_GET['page'] = '7';

        try {
            $container = $this->container();
            (new HttpServiceProvider($container))->register();

            $this->assertSame('7', $container->resolve(Request::class)->input('page'));
        } finally {
            unset($_GET['page']);
        }
    }

    /**
     * A hand-built container needs the same self-bindings app() installs, since
     * ServiceProvider type-hints the interface and the container will not guess
     * at an unbound one.
     */
    private function container(): Container
    {
        $container = new Container();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Container::class, $container);

        return $container;
    }
}
