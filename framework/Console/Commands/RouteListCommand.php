<?php
declare(strict_types=1);

namespace Framework\Console\Commands;

use Framework\Config\Config;
use Framework\Console\Command;
use Framework\Routing\Adapters\RestApiRouterAdapter;
use Framework\Routing\Adapters\WordPressRouterAdapter;
use Framework\Routing\AdminPage;
use Framework\Routing\Route;
use Framework\Routing\RouteDomain;
use Framework\Routing\RouteLoader;
use Framework\Routing\RouteLoadResult;

/**
 * `wp wp_mini route:list` - every registered route, web and REST, in one table.
 *
 * Why this command has to load the routes itself
 *
 * Every other part of the routing layer runs inside a WordPress action. WP-CLI
 * fires none of them - no `rest_api_init`, no `admin_menu`, no `admin_post`, no
 * `template_redirect` - so when this command runs, both routers are empty and the
 * true answer is invisible. Listing nothing would be a *wrong* answer presented
 * confidently, so the command loads both routes files itself through
 * {@see RouteLoader}, the same entry point the service provider uses.
 *
 * Loading is idempotent per file, so running this in a process where a hook has
 * already fired lists what that load collected rather than doubling it.
 *
 * Why the table is built by hand
 *
 * `info()` writes raw lines and WP-CLI renders them literally, so the columns are
 * padded here. Deferring to WP-CLI's own table formatter would make the command
 * untestable outside WP-CLI, which is where its tests run - the exit-code-and-
 * output contract would be unverifiable. The same choice `migrate:status` makes,
 * for the same reason.
 *
 * The Domain column is not decoration
 *
 * It is what makes the table answerable. `leads` may be an admin screen, a
 * form action or a REST endpoint, and those are three different URLs and three
 * different hooks - the URI column shows *which* one, but only because the
 * domain says how to read the URI. A single list without it would be a list of
 * strings that look alike and behave nothing alike.
 */
class RouteListCommand extends Command
{
    private const WIDTH_DOMAIN = 6;
    private const WIDTH_METHOD = 11;
    private const WIDTH_URI = 34;
    private const WIDTH_ACTION = 40;
    private const WIDTH_MIDDLEWARE = 30;

    public function __construct(
        private readonly WordPressRouterAdapter $web,
        private readonly RestApiRouterAdapter $api,
        private readonly RouteLoader $loader
    ) {
    }

    public function getName(): string
    {
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' route:list';
    }

    public function getDescription(): string
    {
        return 'List every registered route and the WordPress hook each one publishes on.';
    }

    public function handle(array $args, array $assocArgs): void
    {
        $failures = $this->loadRoutes();

        foreach ($failures as $failure) {
            // Warned about rather than fatal, and the table still prints: a
            // routes file that fails to load usually takes *some* of the routes
            // with it, and seeing the ones that did register is how the mistake
            // gets found. Aborting here would hide the working half.
            $this->warning($failure->describe());
        }

        $rows = $this->rows();

        if ($rows === []) {
            $this->info('No routes registered.');
            $this->info('Add routes to routes/api.php or routes/web.php.');

            return;
        }

        $this->info($this->header());
        $this->info($this->rule());

        foreach ($rows as $row) {
            $this->info($this->row($row));
        }

        $this->info('');
        $this->info($this->summary($rows));
    }

    /**
     * Load both routes files, collecting any failures to report.
     *
     * @return list<RouteLoadResult>
     */
    private function loadRoutes(): array
    {
        $failures = [];

        /**
         * Order matters only for the output, which is sorted afterwards anyway -
         * but loading API first matches the order the service provider's own
         * hooks tend to fire in, so a failure is reported in the same sequence a
         * developer would see it live.
         */
        foreach ([$this->api, $this->web] as $router) {
            $file = $this->routesFileFor($router);
            $result = $this->loader->load($router, $file);

            if ($result->failed()) {
                $failures[] = $result;
            }
        }

        return $failures;
    }

    /**
     * The routes file that feeds a given router.
     *
     * Resolved by asking the router its domain rather than by hard-coding a path
     * per adapter, so a project that overrides `$webRoutesFile` to move its web
     * routes elsewhere is listed from wherever it actually put them. The config
     * keys are the override point; the defaults match the providers.
     */
    private function routesFileFor(object $router): ?string
    {
        return $router instanceof WordPressRouterAdapter
            ? config('app.web_routes_file', 'routes/web.php')
            : config('app.routes_file', 'routes/api.php');
    }

    /**
     * Every route, as a printable row.
     *
     * Web routes come first because they are the ones a developer cannot
     * otherwise enumerate - a REST route is discoverable from `/wp-json` and a
     * web route is not, so this listing is the only place the whole web surface
     * appears at once.
     *
     * @return list<array<string, string>>
     */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->web->getRoutes() as $route) {
            $rows[] = $this->webRow($route);
        }

        foreach ($this->api->getRoutes() as $route) {
            $rows[] = $this->restRow($route);
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function webRow(Route $route): array
    {
        $page = AdminPage::fromRoute($route);
        $surface = $route->getOption(WordPressRouterAdapter::OPTION_SURFACE);

        // The hook is what a developer actually needs to find: it is the string
        // they would grep for in WordPress's source when a route does not fire.
        // Derived from the same surface decision the adapter publishes on, so the
        // table cannot claim a hook the adapter did not use.
        [$uri, $hook] = match ($surface) {
            WordPressRouterAdapter::SURFACE_ADMIN_PAGE => [
                'admin.php?page=' . $page->slug(),
                'admin_menu',
            ],
            WordPressRouterAdapter::SURFACE_ADMIN_ACTION => [
                'admin-post.php?action=' . ($page->action() ?? '?'),
                'admin_post_' . ($page->action() ?? '?'),
            ],
            default => [
                '/' . trim($route->getPath(), '/'),
                'template_redirect',
            ],
        };

        // The hook is the more useful of the two, so it goes FIRST and the URL
        // follows as a comment on it. WordPress reaches both web surfaces through
        // the same `admin-post.php` and `admin.php` entry points, so the URL's
        // distinguishing part is its query string - which sits at the very end,
        // exactly where truncation cuts. Hook-first means a long row still shows
        // the one string a developer greps for, and loses only the reminder of how
        // a browser gets there.
        $uriColumn = sprintf('%s  (%s)', $hook, $uri);

        return [
            'domain' => RouteDomain::Web->value,
            'method' => implode('|', $route->getMethods()),
            'uri' => $uriColumn,
            'action' => $this->action($route),
            'middleware' => $this->middleware($route),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function restRow(Route $route): array
    {
        $namespace = $this->api->namespace();

        return [
            'domain' => RouteDomain::Api->value,
            'method' => implode('|', $route->getMethods()),
            // The full REST path, namespace included, because a route is only
            // reachable at the namespaced URL and printing the bare path would
            // send a developer to a 404 they would blame on the framework.
            'uri' => sprintf('/wp-json/%s/%s', $namespace, trim($route->getPath(), '/')),
            'action' => $this->action($route),
            'middleware' => $this->middleware($route),
        ];
    }

    /**
     * The `Controller@action` cell, or the closure marker.
     *
     * A closure gets a word rather than an empty cell: an empty cell reads as a
     * route with no handler, which is the one state publish() refuses - so the
     * blank would be alarming and wrong. The file and line are not shown because
     * a closure's reflection line points into the routes file, which the reader
     * already knows.
     *
     * Why the namespace is trimmed
     *
     * A controller's fully-qualified name is long enough to overflow this column
     * on its own - `Tests\Unit\Routing\Fixtures\StringController@index` is 50
     * characters against a width of 40. Left whole, every row truncates to the
     * same generic `Tests\Unit\Routing\Fixtures\StringCon...`, which is the
     * *prefix* every controller shares and therefore the part that distinguishes
     * nothing - while the `@action`, the one thing the reader came for, is cut
     * off the end. The column would be uniformly useless and look fine.
     *
     * So the namespace is dropped and only the class basename is kept. In a real
     * project two controllers of the same basename in different namespaces are
     * possible, and showing only the basename would be ambiguous - but an
     * ambiguous cell that fits beats an unambiguous one nobody can read, and the
     * routes file the reader is about to open names the class in full anyway.
     */
    private function action(Route $route): string
    {
        $controller = $route->getController();

        if ($controller !== null) {
            return $this->shortName($controller) . '@' . ($route->getAction() ?? 'index');
        }

        return $route->getHandler() !== null ? '{closure}' : '(none)';
    }

    /**
     * The class basename - `App\Http\Controllers\LeadController` → `LeadController`.
     *
     * Anonymous classes have no useful basename (their name contains a NUL byte
     * and a file path), so the whole name is kept and the column's truncation
     * handles it, which is better than emitting an empty cell.
     */
    private function shortName(string $class): string
    {
        if (str_contains($class, "@anonymous\0")) {
            return $class;
        }

        $parts = explode('\\', $class);

        return (string) end($parts);
    }

    private function middleware(Route $route): string
    {
        $middleware = $route->getMiddleware();

        if ($middleware === []) {
            return '-';
        }

        // Short names only - the namespace is the same for every row and would
        // consume the column without distinguishing anything. `Authorize` tells
        // a reader as much as `Framework\Http\Middleware\Authorize` in a table
        // whose other columns are already the interesting part.
        return implode(', ', array_map(
            static function (string $class): string {
                $parts = explode('\\', $class);

                return (string) end($parts);
            },
            $middleware
        ));
    }

    /**
     * @param array<string, string> $row
     */
    private function row(array $row): string
    {
        return sprintf(
            '%s %s %s %s %s',
            str_pad($row['domain'], self::WIDTH_DOMAIN),
            str_pad($row['method'], self::WIDTH_METHOD),
            str_pad($this->truncate($row['uri'], self::WIDTH_URI), self::WIDTH_URI),
            str_pad($this->truncate($row['action'], self::WIDTH_ACTION), self::WIDTH_ACTION),
            str_pad($row['middleware'], self::WIDTH_MIDDLEWARE)
        );
    }

    private function header(): string
    {
        return $this->row([
            'domain' => 'Domain',
            'method' => 'Method',
            'uri' => 'URI / Hook',
            'action' => 'Controller@Action',
            'middleware' => 'Middleware',
        ]);
    }

    private function rule(): string
    {
        return str_repeat('-', self::WIDTH_DOMAIN + self::WIDTH_METHOD + self::WIDTH_URI
            + self::WIDTH_ACTION + self::WIDTH_MIDDLEWARE + 4);
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function summary(array $rows): string
    {
        $web = count(array_filter($rows, static fn (array $r): bool => $r['domain'] === RouteDomain::Web->value));
        $api = count($rows) - $web;

        return sprintf(
            '%d route(s): %d web, %d api.',
            count($rows),
            $web,
            $api
        );
    }

    /**
     * Cut a cell to its column width, marking the cut.
     *
     * Without this a long path pushes every column after it out of alignment for
     * that one row - the table stops being readable exactly when a route is long
     * enough to be worth studying. The ellipsis is ASCII rather than `…` because
     * a Windows console in a non-UTF-8 code page renders the multi-byte glyph as
     * mojibake, and the summary counts are what matter most there.
     */
    private function truncate(string $value, int $width): string
    {
        if (strlen($value) <= $width) {
            return $value;
        }

        return substr($value, 0, max(0, $width - 3)) . '...';
    }
}
