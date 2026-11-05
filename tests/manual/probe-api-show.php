<?php
/**
 * Probe: does an int-typed route parameter survive the string a URL carries?
 *
 * ## The trap this probe exists to make visible
 *
 * The question looks like it is about the controller, and it is not: the answer
 * depends on **who makes the call**, not on how the method is declared.
 *
 *   - `$controller->show('1')` from a file with `declare(strict_types=1)` — which
 *     every file in this project has — raises a TypeError. PHP decides coercion
 *     using the mode of the file the *call* is written in.
 *   - The framework dispatches through `Container::call()`, which invokes via
 *     `ReflectionMethod::invokeArgs()`. Reflection invocation is a **weak-mode**
 *     call, so `'1'` is coerced to `1` there.
 *
 * So a probe that calls the controller directly reports a failure that no real
 * request can hit, and a unit test that passes an `int` reports a success that
 * proves nothing about the dispatch path. This measures both frames side by side
 * against the same method, then the real path through the router.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/tests/bootstrap.php';

use App\Http\Controllers\Api\LeadController;
use Framework\Http\Responses\JsonResponse;
use Framework\Routing\RouteResolver;
use Framework\Routing\Router;
use DevLinkStudios\WPServiceContainer\Container;
use Tests\Support\WpStub;

$probes = [];
$probe = function (string $label, bool $ok, string $detail = '') use (&$probes): void {
    $probes[] = $ok;
    printf("  %s %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail === '' ? '' : "  ({$detail})");
};

echo "\nthe same method, called from two different frames\n";

// Frame 1 — a direct call from this strict file.
try {
    (new LeadController())->show('1');
    $probe('a direct strict call rejects the string', false, 'it was accepted');
} catch (\TypeError $e) {
    $probe('a direct strict call rejects the string', true, 'TypeError');
}

// Frame 2 — the container, which is what dispatch actually uses.
$container = new Container();

try {
    $response = $container->call([LeadController::class, 'show'], ['id' => '1']);
    $probe('the container coerces it', $response instanceof JsonResponse, get_debug_type($response));
} catch (\Throwable $e) {
    $probe('the container coerces it', false, get_class($e) . ': ' . $e->getMessage());
}

// Frame 3 — the whole path: route -> router -> binder -> container.
echo "\nthrough the real dispatch path\n";

WpStub::reset();
$container = new Container();
$router = new Router($container, new RouteResolver($container));
$router->registrar()->get('leads/{id}', LeadController::class, 'show');
$router->registerRoutes('wp-mini/v1');

$request = new WP_REST_Request();
$request->set_url_params(['id' => '1']);
$request->set_params(['id' => '1']);

$response = null;

foreach (WpStub::$registeredRoutes['wp-mini/v1'] as $registration) {
    $response = ($registration['callback'])($request);
}

$payload = $response->data()->jsonSerialize();

$probe('the payload carries an int', $payload['id'] === 1, get_debug_type($payload['id']));
$probe('the id is the one from the URL', $payload['id'] === 1);
$probe('a missing lead is a 404', (function () use ($request) {
    $r = new WP_REST_Request();
    $r->set_url_params(['id' => '999']);
    $r->set_params(['id' => '999']);

    foreach (WpStub::$registeredRoutes['wp-mini/v1'] as $registration) {
        $out = ($registration['callback'])($r);

        return $out->status() === 404;
    }

    return false;
})());

echo "\n";

$failed = count(array_filter($probes, fn ($p) => !$p));
printf("%d probes, %d failed\n", count($probes), $failed);
exit($failed === 0 ? 0 : 1);
