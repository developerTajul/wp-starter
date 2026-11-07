<?php
declare(strict_types=1);

namespace Tests\Unit\Console;

use Framework\Routing\Controller;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

/**
 * Generates code with the real shipped stubs, then loads and uses it.
 *
 * A generator that writes a file is not the same as a generator that writes a
 * file that *works*. The stubs carry a namespace and a controller base class,
 * both of which can drift from the framework silently — nothing else in the
 * suite would notice, because nothing else renders a stub into a class and then
 * instantiates it.
 *
 * Each stub is generated into `core/` (already autoloaded as Core\ and ignored
 * by git) and removed again in tearDown.
 */
final class GeneratedCodeTest extends TestCase
{
    private const NAMESPACE = 'Core\\GeneratedFixtures';

    private string $targetDirectory;

    /**
     * Where the shipped stubs live.
     *
     * Resolved through {@see StubLocator} rather than by spelling
     * `<plugin root>/stubs/…`, which is the path this test used to hardcode and
     * which stopped existing the moment the stubs moved into the framework
     * package — the arrangement that lets them ship with the package instead of
     * only with the project that happens to be the template.
     *
     * The workspace below has no `stubs/` directory of its own, so this resolves
     * to the framework's copy and the test additionally pins that fallback: a
     * regression to project-only resolution would fail here rather than silently
     * testing a stale duplicate.
     */
    private function stubPath(string $name): string
    {
        return (new \Framework\Console\StubLocator($this->targetDirectory))->resolve($name);
    }

    protected function setUp(): void
    {
        WpStub::reset();

        $this->targetDirectory = dirname(__DIR__, 3) . '/core/GeneratedFixtures';
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->targetDirectory)) {
            return;
        }

        foreach (glob($this->targetDirectory . '/*.php') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->targetDirectory);
    }

    /**
     * The bare controller stub must produce a loadable class and nothing more.
     *
     * `make:controller Foo` produces an empty subclass — no actions, no routes —
     * and that is the assertion: the failure mode is a default stub that grew a
     * set of working-looking actions back, which would ship live routes for every
     * bare `make:controller` in the wild. The class still has to load and extend
     * the base, because a bare stub that failed to resolve its namespace or its
     * parent would break the project the moment it was written.
     */
    public function test_the_controller_stub_produces_a_bare_loadable_class(): void
    {
        $class = 'GeneratedBare';
        $path = $this->generateFixture($class, 'controller.stub');

        $this->assertFileExists($path);

        /** @var class-string<Controller> $fqcn */
        $fqcn = self::NAMESPACE . '\\' . $class;
        $this->assertTrue(class_exists($fqcn), "The generated class [{$fqcn}] did not autoload.");

        $controller = new $fqcn();
        $this->assertInstanceOf(Controller::class, $controller);

        // No actions at all — the whole point of the bare shape. The methods
        // inherited from the base are excluded by asking which ones the subclass
        // itself declares.
        $declared = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass($fqcn))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );

        $inherited = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new \ReflectionClass(Controller::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );

        $this->assertSame(
            [],
            array_values(array_diff($declared, $inherited)),
            'A bare controller must declare no actions of its own.'
        );
    }

    /**
     * The API controller stub must produce a loadable class whose #[Route]
     * attributes register routes — proving the stub's namespace, imports and
     * attribute usage all match the framework.
     */
    public function test_the_api_controller_stub_produces_a_working_controller(): void
    {
        $class = 'GeneratedPosts';
        $path = $this->generateFixture($class, 'controller.api.stub');

        $this->assertFileExists($path);

        /** @var class-string<Controller> $fqcn */
        $fqcn = self::NAMESPACE . '\\' . $class;
        $this->assertTrue(class_exists($fqcn), "The generated class [{$fqcn}] did not autoload.");

        $controller = new $fqcn();
        $this->assertInstanceOf(Controller::class, $controller);

        $router = (new \Framework\Routing\Router(
            new \DevLinkStudios\WPServiceContainer\Container(),
            new \Framework\Routing\RouteResolver(new \DevLinkStudios\WPServiceContainer\Container())
        ));

        /** @var Controller $controller */
        $controller->registerRoutes($router->registrar());

        $actions = array_map(
            static fn (\Framework\Routing\Route $route): ?string => $route->getAction(),
            $router->getRoutes()
        );

        $this->assertContains('index', $actions);
        $this->assertContains('show', $actions);
        $this->assertContains('store', $actions);
    }

    /**
     * The generated API controller's declared return type must match what its own
     * inherited helpers return, or every endpoint in it would be a TypeError.
     */
    public function test_the_generated_controller_returns_response_objects(): void
    {
        $class = 'GeneratedResponses';
        $this->generateFixture($class, 'controller.api.stub');

        /** @var class-string<Controller> $fqcn */
        $fqcn = self::NAMESPACE . '\\' . $class;

        $controller = new $fqcn();
        $request = new \WP_REST_Request();

        $this->assertInstanceOf(\WP_REST_Response::class, $controller->index($request));
        $this->assertInstanceOf(\WP_REST_Response::class, $controller->store($request));
        $this->assertSame(201, $controller->store($request)->get_status());
    }

    /**
     * The routes stub is required directly by the provider, so it must return a
     * callable and register its documented endpoint without WordPress present
     * beyond the stubs.
     */
    public function test_the_routes_stub_returns_a_usable_registrar_callback(): void
    {
        $stubPath = $this->stubPath('routes.stub');

        /** @var callable $callback */
        $callback = require $stubPath;

        $this->assertIsCallable($callback);

        $container = new \DevLinkStudios\WPServiceContainer\Container();
        $router = new \Framework\Routing\Router($container, new \Framework\Routing\RouteResolver($container));

        $callback($router->registrar());

        $this->assertNotSame([], $router->getRoutes());
        $this->assertSame('ping', $router->getRoutes()[0]->getPath());
    }

    /**
     * The provider stub must be a valid ServiceProvider, since the entry file
     * registers whatever config/app.php lists — a bad stub would break a
     * project the moment it added its first provider.
     */
    public function test_the_provider_stub_is_a_valid_service_provider(): void
    {
        $stub = (string) file_get_contents($this->stubPath('provider.stub'));

        $contents = str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [self::NAMESPACE, 'GeneratedProvider'],
            $stub
        );

        $target = $this->targetDirectory . '/GeneratedProvider.php';
        $this->write($target, $contents);

        /** @var class-string $fqcn */
        $fqcn = self::NAMESPACE . '\\GeneratedProvider';
        $this->assertTrue(class_exists($fqcn));

        $container = new \DevLinkStudios\WPServiceContainer\Container();

        /** @var \DevLinkStudios\WPServiceContainer\ServiceProvider $provider */
        $provider = new $fqcn($container);
        $provider->register();
        $provider->boot();

        // Neither phase may throw: an empty register()/boot() is the point of
        // the stub, and a stub that resolved something would fail here.
        $this->assertTrue(true);
    }

    /**
     * Render a shipped controller stub into the fixture namespace and load it.
     *
     * The stub is named by the caller rather than fixed, because the four
     * controller shapes are four different files and the questions asked of them
     * differ: the bare stub is checked for having no actions, the API stub for
     * registering REST routes. Rendering "the controller stub" generically would
     * make every assertion here a claim about whichever file that happened to be.
     *
     * @param string $stub A stub filename, e.g. `controller.api.stub`.
     */
    private function generateFixture(string $class, string $stub): string
    {
        $contents = str_replace(
            ['{{ namespace }}', '{{ class }}'],
            [self::NAMESPACE, $class],
            (string) file_get_contents($this->stubPath($stub))
        );

        // The route/view placeholders the API and web stubs carry, so the
        // rendered file is valid source. The values are never asserted on here —
        // the route *paths* are asserted in `MakeControllerCommandTest`, which
        // goes through the command that actually substitutes them.
        $contents = str_replace(
            ['{{ route }}', '{{ name }}', '{{ view }}', '{{ items }}', '{{ item }}'],
            [$class, lcfirst($class), strtolower($class), strtolower($class) . 's', lcfirst($class)],
            $contents
        );

        $path = $this->targetDirectory . '/' . $class . '.php';
        $this->write($path, $contents);

        require_once $path;

        return $path;
    }

    private function write(string $path, string $contents): void
    {
        if (!is_dir($this->targetDirectory)) {
            mkdir($this->targetDirectory, 0755, true);
        }

        file_put_contents($path, $contents);
    }
}
