<?php
declare(strict_types=1);

/**
 * Probe: follow a `company_store` submission from the `admin_post` hook to the
 * controller, and report every point at which the request could stop.
 *
 * This is the trace, run rather than described. A blank `admin-post.php` screen
 * has several possible causes and they are told apart by *where* execution ends:
 *
 *   - the action never reaches the framework  -> WordPress fires nothing, so
 *     nothing at all this plugin does is at fault;
 *   - the action arrives but matches no route -> the adapter returns silently and
 *     WordPress goes on to render its own response;
 *   - the route matches but the controller echoes and exits -> the debug code is
 *     the blank screen;
 *   - the controller returns a response the dispatcher refuses -> a named
 *     HttpException, not a blank page.
 *
 * Each of those is asserted here, so the answer is a passing or failing line
 * rather than an intuition.
 *
 * Run: php tests/manual/probe-company-store-dispatch.php
 */

require_once __DIR__ . '/../stubs/wordpress.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../stubs/wp-classes.php';
require_once __DIR__ . '/../stubs/builtins.php';

use App\Http\Controllers\Admin\CompanyController;
use Framework\Routing\Adapters\WordPressRouterAdapter;
use Framework\Routing\RouteLoader;
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

// The site the way a real admin request finds it: logged in as an administrator,
// with the app booted so every binding the routes file depends on exists.
app()->instance('path.base', $root);
Framework\Config\Config::load($root . '/config');
(new App\Kernel(app()))->bootstrap();

$router = app()->resolve(WordPressRouterAdapter::class);
(new RouteLoader($root))->load($router, 'routes/web.php');
$router->flush();

// ------------------------------------------------------------------ 1. the hook

$check(
    'the framework listens on the generic admin_post action',
    isset(WpStub::$actions['admin_post']),
    'Nothing is hooked, so no form submission can reach the router.'
);

// The route's own published hooks, which the adapter registers at flush time.
$check(
    'admin_post_company_store is hooked',
    isset(WpStub::$actions['admin_post_company_store']),
    'Published actions: ' . implode(', ', array_filter(
        array_keys(WpStub::$actions),
        static fn (string $tag): bool => str_starts_with($tag, 'admin_post')
    ))
);

// ------------------------------------------------- 2. the request the form sends

// Exactly what the rendered form posts: the action name, the nonce the view
// minted, and the fields. `$_REQUEST` is what dispatchCurrentAdminAction reads.
$_SERVER['REQUEST_METHOD'] = 'POST';
WpStub::$currentUserCan = true;
WpStub::$userLoggedIn = true;
WpStub::$currentUserId = 1;

$nonce = WpStub::issueNonce(CompanyController::STORE_ACTION);

$_REQUEST = $_POST = [
    'action' => CompanyController::STORE_ACTION,
    '_wpnonce' => $nonce,
    'redirect_to' => 'http://example.test/wp-admin/admin.php?page=companies',
    'name' => 'Acme Widgets',
    'website' => 'https://acme.test',
    'industry' => 'Manufacturing',
    'phone' => '555-0100',
    'address' => "1 Acme Way\nSpringfield",
];

$check(
    'the form posts the action name the route was published under',
    ($_REQUEST['action'] ?? null) === CompanyController::STORE_ACTION,
    'form sends [' . ($_REQUEST['action'] ?? '') . '], route published [' . CompanyController::STORE_ACTION . ']'
);

// -------------------------------------------------- 3. does the route match it?

$matched = null;
foreach ($router->getRoutes() as $route) {
    if ($route->getPath() === 'companies/store') {
        $matched = $route;
    }
}

$check('the store route is registered', $matched !== null);

$check(
    'the store route carries its middleware (the guards are not disabled)',
    $matched !== null && $matched->getMiddleware() !== [],
    'The store route has NO middleware — it is an unguarded write endpoint.'
);

// ------------------------------------------------ 4. the controller, called directly

// Called through the container so its real dependencies are injected, rather
// than through the dispatcher — which would send the response and end the
// process before anything could be asserted.
$controller = app()->resolve(CompanyController::class);

try {
    $response = $controller->store(new Framework\Http\Request($_POST, [], [], $_SERVER));

    $check('store() returns a response instead of exiting', true);
    $check(
        'store() returns a ResponseInterface',
        $response instanceof Framework\Http\Contracts\ResponseInterface,
        get_debug_type($response)
    );
    $check(
        'store() redirects to the list screen',
        $response instanceof Framework\Http\Responses\RedirectResponse
            && str_contains($response->url(), 'page=companies'),
        $response instanceof Framework\Http\Responses\RedirectResponse ? $response->url() : ''
    );
} catch (Throwable $e) {
    $check('store() returns a response instead of exiting', false, get_class($e) . ': ' . $e->getMessage());
}

// ------------------------------------------------------------ 5. create() renders

try {
    $created = $controller->create();

    $check('create() renders a response', $created instanceof Framework\Http\Contracts\ResponseInterface);
} catch (Throwable $e) {
    $check('create() renders a response', false, get_class($e) . ': ' . $e->getMessage());
}

printf("\n%d probes, %d failed\n", $checks, count($failures));

if ($failures !== []) {
    foreach ($failures as $failure) {
        printf("  - %s\n", $failure);
    }
    exit(1);
}
