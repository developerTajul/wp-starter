<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use Framework\Config\Config;
use DevLinkStudios\WPServiceContainer\Container;
use Framework\Console\Commands\RouteListCommand;
use Framework\Routing\Adapters\RestApiRouterAdapter;
use Framework\Routing\Adapters\WordPressRouterAdapter;
use Framework\Routing\RouteLoader;
use Framework\Routing\RouteResolver;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;
use Tests\Unit\Routing\Fixtures\FakeWebRequestFactory;

/**
 * `route:list` renders both domains in one table.
 *
 * ## What this command is actually for
 *
 * It is the only place the *whole* routing surface appears at once. A REST route
 * is discoverable from `/wp-json`; a web route is discoverable from nowhere —
 * `admin_menu` registrations are buried in a callback and `admin_post_{action}`
 * hooks are derived from paths at flush time. So the value of this command is
 * completeness and the hook names, and those are what these tests pin.
 *
 * ## Why the command loads the routes itself
 *
 * WP-CLI fires none of `rest_api_init`, `admin_menu`, `admin_post` or
 * `template_redirect`, so in the process this command actually runs in, both
 * routers are empty. A command that just listed what it found would print
 * "no routes" for a plugin full of them — a wrong answer delivered confidently,
 * which is worse than an error. The load goes through {@see RouteLoader}, and
 * `test_it_loads_the_routes_files_itself` is what keeps that true.
 */
final class RouteListCommandTest extends TestCase
{
    private Container $container;
    private WordPressRouterAdapter $web;
    private RestApiRouterAdapter $api;
    private RouteLoader $loader;

    protected function setUp(): void
    {
        WpStub::reset();
        FakeWebRequestFactory::reset();

        $this->container = new Container();
        $this->container->instance(
            \DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface::class,
            $this->container
        );

        $resolver = new RouteResolver($this->container);

        $this->web = new WordPressRouterAdapter(
            $this->container,
            $resolver,
            new FakeWebRequestFactory()
        );

        $this->api = (new RestApiRouterAdapter($this->container, $resolver))
            ->publishUnder('test/v1');

        // A base path that contains no routes files, so the default command run
        // loads nothing and each test controls its own table. The load behaviour
        // itself is pinned separately, by the loading tests below.
        $this->loader = new RouteLoader($this->emptyDirectory());
    }

    /**
     * A directory guaranteed to contain no routes files.
     *
     * Not a fixed path: a stray `web.php` left in a shared temp directory by some
     * earlier run would silently add routes to every test that expects an empty
     * table, and the failure would read as a bug in the command.
     */
    private function emptyDirectory(): string
    {
        $dir = sys_get_temp_dir() . '/wp-mini-laravel-empty-' . bin2hex(random_bytes(4));

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir;
    }

    // ------------------------------------------------------------- the listing

    /**
     * Both domains appear in one table — the reason the command exists.
     */
    public function test_web_and_rest_routes_are_listed_together(): void
    {
        $this->web->adminPage('leads', Fixtures\StringController::class, 'index');
        $this->api->get('reports', Fixtures\ReportController::class, 'index');

        $output = $this->render();

        $this->assertStringContainsString('web', $output);
        $this->assertStringContainsString('api', $output);
        $this->assertStringContainsString('admin_menu', $output);
        $this->assertStringContainsString('wp-json/test/v1/reports', $output);
    }

    /**
     * The header names every column the brief asks for.
     */
    public function test_the_header_names_every_column(): void
    {
        $this->web->adminPage('leads', Fixtures\StringController::class, 'index');

        $output = $this->render();

        foreach (['Domain', 'Method', 'URI / Hook', 'Controller@Action', 'Middleware'] as $column) {
            $this->assertStringContainsString($column, $output);
        }
    }

    /**
     * The controller and action are shown as `Class@method`, which is the form a
     * developer reads the routes file in.
     */
    public function test_the_controller_and_action_are_shown(): void
    {
        $this->web->adminPage('leads', Fixtures\StringController::class, 'index');

        $this->assertStringContainsString('StringController@index', $this->render());
    }

    /**
     * A closure handler is labelled rather than left blank — a blank cell reads as
     * a route with no handler, which is the one state publish() refuses.
     */
    public function test_a_closure_handler_is_labelled(): void
    {
        $this->api->get('ping')->handle(static fn (): array => ['pong' => true]);

        $output = $this->render();

        $this->assertStringContainsString('{closure}', $output);
        $this->assertStringNotContainsString('(none)', $output);
    }

    /**
     * The hook each route publishes on is shown, because that is the string a
     * developer greps for when a route does not fire.
     *
     * `flush()` is called first, and that is not incidental. A form handler's
     * `admin_post_{action}` name is *derived at publish time* — the adapter reads
     * the path, applies the verb suffix and records the result back on the route.
     * Before that, the route genuinely has no action name, so a listing that
     * guessed one would be reporting a hook nobody registered. The test flushes
     * for the same reason production does.
     */
    public function test_each_web_surface_reports_its_hook(): void
    {
        $this->web->adminPage('leads', Fixtures\StringController::class, 'index');
        $this->web->adminAction('leads-store', Fixtures\StringController::class, 'store');
        $this->web->page('thank-you', Fixtures\StringController::class, 'index');
        $this->web->flush();

        $output = $this->render();

        $this->assertStringContainsString('admin_menu', $output);
        $this->assertStringContainsString('admin_post_leads_store', $output);
        $this->assertStringContainsString('template_redirect', $output);
    }

    /**
     * A form handler that has not been published yet shows `?` rather than a
     * plausible-looking guess.
     *
     * This is the honest answer: the name is derived at flush time, and a listing
     * that invented `admin_post_leads_store` before WordPress had ever been told
     * that hook existed would send a developer to debug a hook that is not
     * registered — the worst kind of wrong answer, because it looks right.
     */
    public function test_an_unpublished_action_reports_an_unknown_hook(): void
    {
        $this->web->adminAction('leads-store', Fixtures\StringController::class, 'store');

        $this->assertStringContainsString('admin_post_?', $this->render());
    }

    /**
     * Middleware is listed by short name — the namespace is identical on every row
     * and would consume the column without distinguishing anything.
     */
    public function test_middleware_is_listed_by_short_name(): void
    {
        $this->web->adminPage('leads', Fixtures\StringController::class, 'index')
            ->middleware(Fixtures\AllowMiddleware::class);

        $output = $this->render();

        $this->assertStringContainsString('AllowMiddleware', $output);
        $this->assertStringNotContainsString('Tests\\Unit\\Routing\\Fixtures\\AllowMiddleware', $output);
    }

    /**
     * A route with no middleware shows a dash rather than nothing, so an empty
     * cell is never ambiguous between "none" and "column shifted".
     */
    public function test_a_route_without_middleware_shows_a_dash(): void
    {
        $this->web->adminPage('leads', Fixtures\StringController::class, 'index');

        $this->assertMatchesRegularExpression('/leads.*-\s*$/m', $this->render());
    }

    /**
     * A long path must not push the following columns out of alignment — the table
     * has to stay readable exactly when a route is long enough to be studied.
     */
    public function test_a_long_path_is_truncated_to_keep_the_columns_aligned(): void
    {
        $this->web->adminPage(
            'an-extremely-long-admin-screen-slug-that-would-otherwise-break-the-table',
            Fixtures\StringController::class,
            'index'
        );

        $output = $this->render();

        $this->assertStringContainsString('...', $output);

        foreach (explode("\n", $output) as $line) {
            $this->assertLessThan(
                160,
                strlen(rtrim($line)),
                'A row must not be wider than the table.'
            );
        }
    }

    // ------------------------------------------------------------ the summary

    public function test_the_summary_counts_both_domains(): void
    {
        $this->web->adminPage('leads', Fixtures\StringController::class, 'index');
        $this->api->get('reports', Fixtures\ReportController::class, 'index');
        $this->api->post('reports', Fixtures\ReportController::class, 'index');

        $output = $this->render();

        $this->assertStringContainsString('3 route(s)', $output);
        $this->assertStringContainsString('1 web', $output);
        $this->assertStringContainsString('2 api', $output);
    }

    /**
     * An empty table says so, and says where routes would go — an empty list with
     * no next step is a dead end.
     */
    public function test_an_empty_table_explains_itself(): void
    {
        $output = $this->render();

        $this->assertStringContainsString('No routes registered', $output);
        $this->assertStringContainsString('routes/web.php', $output);
    }

    // ------------------------------------------------------------- route loading

    /**
     * **The reason this command reads the routes files at all.**
     *
     * Under WP-CLI none of the four routing actions fire, so both tables are empty
     * and a command that listed what it found would report a plugin's whole surface
     * as missing. The loader is what closes that gap, and this pins that the
     * command calls it.
     */
    public function test_it_loads_the_routes_files_itself(): void
    {
        $loader = $this->loaderWithRoutes([
            'routes/web.php' => $this->facadeRoutes("Route::get('from-the-file', [\stdClass::class, 'shorten']);"),
        ]);

        $output = $this->render($loader);

        // Asserted on the controller and action rather than the path: the path
        // cell is truncated to fit its column, so a substring assertion against a
        // long path would fail for a route that loaded perfectly — a test that
        // reports the column width as a routing bug.
        $this->assertStringContainsString('stdClass@shorten', $output);

        // `admin_menu`, because an unnamed `get()` in a web routes file is an
        // admin screen — that is the surface this dispatch infers, and the hook
        // column is where the inference becomes visible. This was
        // `template_redirect` while a bare `get()` meant "front-end page"; the
        // assertion moved with the meaning rather than being relaxed to match
        // whatever the command happened to print.
        $this->assertStringContainsString('admin_menu', $output);
        $this->assertStringNotContainsString('No routes registered', $output);
    }

    /**
     * **A facade-shaped routes file is the happy path, and this is it.**
     *
     * The fixture above calls `Route::get(...)` — the shape every routes file in
     * this project now has. It loads only because {@see RouteLoader} aims the
     * facade at the router for the duration of the `require`; a file read any
     * other way throws "the facade was called outside a routes file" and
     * contributes nothing.
     *
     * A closure fixture would not catch that: it receives its registrar as an
     * argument and never touches the facade, so it would keep passing while the
     * seam that makes real routes files work was broken. Both shapes are still
     * supported, so both are still covered — this is the one that matters.
     */
    public function test_a_facade_style_routes_file_loads_through_the_loader(): void
    {
        $loader = $this->loaderWithRoutes([
            'routes/web.php' => $this->facadeRoutes(<<<'PHP'
                Route::get('facade-web', [\stdClass::class, 'index']);
                Route::post('facade-save', [\stdClass::class, 'store'])->name('facade.save');
                PHP),
            'routes/api.php' => $this->facadeRoutes("Route::get('facade-api', [\stdClass::class, 'index']);"),
        ]);

        $output = $this->render($loader);

        $this->assertStringContainsString('stdClass@index', $output);
        $this->assertStringContainsString('stdClass@store', $output);
        $this->assertStringContainsString('3 route(s): 2 web, 1 api.', $output);
        $this->assertStringNotContainsString('Failed to load', $output);
    }

    /**
     * Loading is idempotent per file: running the command in a process where a
     * hook has already loaded the routes must list them once, and quietly.
     *
     * ## What this actually catches
     *
     * The route *count* is not the signal, and asserting on it is how this test
     * first passed while the guard it names was disabled. `AbstractRouterAdapter`
     * independently refuses a duplicate `METHOD /path`, so a second load throws
     * that error instead of adding a row — the table reads `1 route(s)` either
     * way, and the summary is blind to the difference.
     *
     * The difference is the warning. Without the guard, the file re-runs, the
     * router rejects the re-registration, and the operator sees a scary
     * "duplicate route" warning on an ordinary `route:list` run. So the assertion
     * is that the second render is silent.
     */
    public function test_loading_the_same_file_twice_does_not_duplicate_routes(): void
    {
        $loader = $this->loaderWithRoutes([
            'routes/web.php' => <<<'PHP'
                <?php
                return function (\Framework\Routing\RouteRegistrar $router): void {
                    $router->get('once-only', \stdClass::class, 'index');
                };
                PHP,
        ]);

        $this->render($loader);
        $output = $this->render($loader);

        $this->assertStringContainsString('1 route(s): 1 web, 0 api.', $output);
        $this->assertStringNotContainsString(
            'Failed to load',
            $output,
            'A second load must be skipped, not re-run and rejected as a duplicate.'
        );
    }

    /**
     * A routes file that throws is reported as a warning and the rest is still
     * listed — a broken file usually takes *some* routes with it, and seeing the
     * ones that did register is how the mistake gets found.
     */
    public function test_a_broken_routes_file_is_warned_about_not_fatal(): void
    {
        $loader = $this->loaderWithRoutes([
            'routes/web.php' => $this->facadeRoutes(<<<'PHP'
                Route::get('good', [\stdClass::class, 'index']);
                throw new \RuntimeException('routes file exploded');
                PHP),
        ]);

        $output = $this->render($loader);

        $this->assertStringContainsString('exploded', $output);
        $this->assertStringContainsString('good', $output);
    }

    /**
     * A routes file that is not valid PHP is reported, and the other domain still
     * lists — one broken file must not take the whole command down with it.
     *
     * ## What this test used to be, and why it changed
     *
     * It asserted that a file *returning a string* produced "must return a
     * callable". That check is gone by design, not by accident: a facade-style
     * routes file's last statement is a `Route::get(...)` call, so the file
     * returns a `Route` object — not callable — while being perfectly correct.
     * Keeping the old assertion would mean keeping a rule that rejects the
     * framework's own happy path.
     *
     * A parse error is the thing that genuinely cannot be loaded, so it is what
     * the warning is now pinned to.
     */
    public function test_a_routes_file_that_cannot_be_parsed_is_reported(): void
    {
        $loader = $this->loaderWithRoutes([
            'routes/web.php' => '<?php this is not valid php',
            'routes/api.php' => $this->facadeRoutes("Route::get('still-listed', [\stdClass::class, 'index']);"),
        ]);

        $output = $this->render($loader);

        $this->assertStringContainsString('Failed to load', $output);
        $this->assertStringContainsString('still-listed', $output);
    }

    // ------------------------------------------------------------------ wiring

    public function test_the_project_registers_the_route_list_command(): void
    {
        $commands = (new \App\Console\Kernel(new Container()))->getCommands();

        $this->assertContains(RouteListCommand::class, $commands);
    }

    public function test_the_command_name_is_prefixed(): void
    {
        $this->assertSame(
            config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' route:list',
            $this->command()->getName()
        );
    }

    public function test_the_command_declares_a_description(): void
    {
        $this->assertNotSame('', $this->command()->getDescription());
    }

    // ----------------------------------------------------------------- helpers

    /**
     * The loader used by default: a directory with no routes files in it.
     */
    private function loader(): RouteLoader
    {
        return $this->loader;
    }

    /**
     * A facade-style routes file wrapping the given body.
     *
     * The `use` line is what a real file has, and it is load-bearing here rather
     * than cosmetic: it is the exact statement that pulls in the facade whose
     * target only exists while the loader is running the file.
     *
     * Written to a temp file with no namespace of its own, so `Route::` resolves
     * through that import — the same as `routes/web.php`, and deliberately not
     * `\Route::`, which would name a global class that does not exist and defeat
     * the import the fixture exists to exercise. The body therefore references
     * `Route` unqualified; `\stdClass` inside it may stay qualified.
     */
    private function facadeRoutes(string $body): string
    {
        return "<?php\nuse Framework\\Support\\Facades\\Route;\n\n" . $body . "\n";
    }

    /**
     * A loader whose base path contains the given files.
     *
     * The paths are relative to the base path and mirror the real layout — the
     * command asks the config for `routes/web.php` and `routes/api.php`, so the
     * fixture has to put them there or the command would look in the wrong place
     * and the test would pass on a technicality it did not intend to test.
     *
     * Written into a unique temporary directory rather than committed, because
     * the content is the point: a committed fixture would have to be generic, and
     * the interesting cases are a file that throws part-way through and one that
     * returns a string.
     *
     * @param array<string, string> $files relative path => contents
     */
    private function loaderWithRoutes(array $files): RouteLoader
    {
        $dir = $this->emptyDirectory();

        foreach ($files as $name => $contents) {
            $path = $dir . '/' . $name;
            $parent = dirname($path);

            if (!is_dir($parent)) {
                mkdir($parent, 0777, true);
            }

            file_put_contents($path, $contents);
        }

        return new RouteLoader($dir);
    }

    /**
     * Run the command and return everything it printed.
     *
     * A recording subclass rather than WP-CLI, which is not loaded in a test — and
     * which would render the table through its own formatter, making the alignment
     * assertions meaningless.
     */
    private function render(?RouteLoader $loader = null): string
    {
        $command = $this->command($loader);
        $command->handle([], []);

        return implode("\n", $command->messages);
    }

    private function command(?RouteLoader $loader = null): RecordingRouteListCommand
    {
        return new RecordingRouteListCommand(
            $this->web,
            $this->api,
            $loader ?? $this->loader()
        );
    }
}

/**
 * {@see RouteListCommand} with its output recorded instead of sent to WP-CLI.
 *
 * The protected helpers guard on `class_exists('WP_CLI')`, so outside WP-CLI a
 * command prints nothing at all — which would make every assertion here vacuous.
 * Recording what it *would* have printed is what makes the table assertable.
 */
final class RecordingRouteListCommand extends RouteListCommand
{
    /** @var list<string> */
    public array $messages = [];

    protected function info(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function warning(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function error(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function success(string $message): void
    {
        $this->messages[] = $message;
    }
}
