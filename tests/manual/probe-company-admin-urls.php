<?php
declare(strict_types=1);

/**
 * Probe: do the companies admin screens agree about their own slugs?
 *
 * The bug this exists to catch is a disagreement, not a single wrong line. Three
 * things have to name one slug for a screen to be reachable:
 *
 *   1. `add_menu_page()` / `add_submenu_page()` — what WordPress will render and
 *      dispatch on, taken from the route's path.
 *   2. `UrlGenerator::compileAdminPage()` — what a link or a redirect is built
 *      from, also taken from the route's path.
 *   3. The request itself — `admin.php?page={slug}`, which WordPress matches
 *      against (1).
 *
 * When (1) and (2) agree the screen works. The failure mode is subtle because a
 * *second* thing can go wrong first: an action that redirects to a route name
 * nothing declares throws from inside a controller, and the resulting error page
 * is rendered at whichever URL the browser originally loaded. The 404 then looks
 * like it belongs to that URL rather than to the redirect that caused it.
 *
 * Run: php tests/manual/probe-company-admin-urls.php
 */

require_once __DIR__ . '/../stubs/wordpress.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../stubs/wp-classes.php';
require_once __DIR__ . '/../stubs/builtins.php';

use App\Http\Controllers\Admin\CompanyController;
use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use Framework\Http\HttpServiceProvider;
use Framework\Routing\CaptureWebRequestFactory;
use Framework\Routing\NamedRoutes;
use Framework\Routing\RouteLoader;
use Framework\Routing\RouteResolver;
use Framework\Routing\Adapters\WordPressRouterAdapter;
use Framework\Routing\UrlGenerator;
use Tests\Support\WpStub;

WpStub::reset();

$root = dirname(__DIR__, 2);

$checks = 0;
$failures = [];
$check = static function (string $label, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks++;
    printf("%s %s%s\n", $ok ? ' ok ' : 'FAIL', $label, $ok || $detail === '' ? '' : ' — ' . $detail);
    if (!$ok) {
        $failures[] = $label;
    }
};

$container = new Container();
$container->instance(ContainerInterface::class, $container);
(new HttpServiceProvider($container))->register();

$named = new NamedRoutes();

$router = new WordPressRouterAdapter(
    $container,
    new RouteResolver($container),
    new CaptureWebRequestFactory(),
    null,
    null,
    $named
);

$result = (new RouteLoader($root))->load($router, 'routes/web.php');

$check('routes/web.php loads', !$result->failed(), $result->describe());
$router->flush();
WpStub::doAction('admin_menu');

// ---------------------------------------------------------------- what WordPress got

$topLevel = array_column(WpStub::$menuPages, 'menu_slug');
$submenus = WpStub::$submenuPages;

$check(
    'the companies list screen is registered as a top-level menu',
    in_array(CompanyController::SCREEN_SLUG, $topLevel, true),
    'Registered: ' . implode(', ', $topLevel)
);

$createSubmenu = null;
foreach ($submenus as $submenu) {
    if ($submenu['menu_slug'] === CompanyController::CREATE_SLUG) {
        $createSubmenu = $submenu;
    }
}

$check(
    'the add-company screen is registered as a submenu of the list screen',
    $createSubmenu !== null && $createSubmenu['parent_slug'] === CompanyController::SCREEN_SLUG,
    $createSubmenu === null
        ? 'No submenu with slug ' . CompanyController::CREATE_SLUG
        : sprintf('parent_slug was [%s]', $createSubmenu['parent_slug'])
);

// ------------------------------------------------------------ what the URLs say

$urls = new UrlGenerator($named);

/**
 * The `page` parameter a generated admin URL carries.
 *
 * Compared as a parameter rather than as a whole string, because the site's own
 * origin is WordPress's business — the stubs answer with one and a CLI process
 * would not. What this probe is about is the *slug*, which is the part the
 * framework computes and the part both sides have to agree on.
 */
$pageOf = static function (string $url): ?string {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return is_string($query['page'] ?? null) ? $query['page'] : null;
};

$check(
    'route(companies.index) addresses the registered list slug',
    $pageOf($urls->route('companies.index')) === CompanyController::SCREEN_SLUG,
    (string) $pageOf($urls->route('companies.index'))
);

$check(
    'route(companies.create) addresses the registered create slug',
    $pageOf($urls->route('companies.create')) === CompanyController::CREATE_SLUG,
    (string) $pageOf($urls->route('companies.create'))
);

// The two must be the same string, because WordPress dispatched on the second
// and the generator produced the first.
$check(
    'the URL the create screen is linked at IS the slug WordPress registered',
    $createSubmenu !== null
        && $pageOf($urls->route('companies.create')) === $createSubmenu['menu_slug'],
    $createSubmenu === null ? 'no create submenu registered' : 'slug mismatch'
);

// ---------------------------------------------------- redirect targets must exist

/**
 * The names `CompanyController` redirects to after a write.
 *
 * Held here as a literal list rather than scraped, because the point is to catch
 * the controller and the routes file drifting apart — a list derived from either
 * one could not do that. These are the three `->route(...)` targets in
 * `store()`, `update()` and `destroy()`.
 */
foreach (['companies.index', 'company.store', 'company.update'] as $name) {
    $check(
        sprintf('route name [%s] resolves (a redirect to a name nothing declares is a 404)', $name),
        $urls->has($name),
        $urls->has($name) ? '' : 'NOT DECLARED'
    );
}

// ------------------------------------------------------- the controller, rendered

// Booted and loaded the way a real admin request is: providers registered, then
// the routes file read again into the *application's* router. The probe's own
// `$router` above is a separate instance with its own `NamedRoutes`, so a
// controller resolved from the container would otherwise render against an empty
// registry and fail on a missing name rather than on anything real. Registering
// into the app's router — the same one `RouteServiceProvider` binds — is what
// makes the final check measure the controller rather than the harness.
app()->instance('path.base', $root);
Framework\Config\Config::load($root . '/config');
(new App\Kernel(app()))->bootstrap();

$appRouter = app()->resolve(WordPressRouterAdapter::class);
(new RouteLoader($root))->load($appRouter, 'routes/web.php');
$appRouter->flush();

$controller = app()->resolve(CompanyController::class);

$check(
    'CompanyController::create() renders without throwing',
    (static function () use ($controller): bool {
        try {
            $controller->create();

            return true;
        } catch (Throwable $e) {
            printf("     (%s: %s)\n", get_class($e), $e->getMessage());

            return false;
        }
    })(),
    'it throws, which is what paints a 404 over admin.php?page=companies'
);

printf("\n%d probes, %d failed\n", $checks, count($failures));

if ($failures !== []) {
    foreach ($failures as $failure) {
        printf("  - %s\n", $failure);
    }
    exit(1);
}
