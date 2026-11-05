<?php
/**
 * Probe: do the lead routes actually register and resolve?
 *
 * A route file that fatals is invisible until a request hits it, so this loads
 * the real route file through the real registrar and asserts the two lead routes
 * resolve to the right controller actions.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Routing\RouteRegistrar;
use Framework\Routing\RouteResolver;
use Framework\Routing\Router;

$probes = [];
$probe = function (string $label, bool $ok, string $detail = '') use (&$probes): void {
    $probes[] = $ok;
    printf("  %s %s%s\n", $ok ? 'ok  ' : 'FAIL', $label, $detail === '' ? '' : "  ({$detail})");
};

$container = new Container();
$router = new Router($container, new RouteResolver($container));
$registrar = $router->registrar();

$loader = require dirname(__DIR__, 2) . '/routes/api.php';
$loader($registrar);

$routes = $router->getRoutes();
$found = [];
foreach ($routes as $route) {
    $found[$route->getPath()] = $route;
}

echo "\nregistered routes\n";
foreach ($found as $path => $route) {
    printf("       %-6s %s -> %s@%s\n", implode('|', $route->getMethods()), $path, $route->getController(), $route->getAction());
}

$probe('the route file loads and registers', $found !== []);
$probe('ping is registered', isset($found['ping']));
$probe('leads is registered', isset($found['leads']));
$probe('leads/{id} is registered', isset($found['leads/{id}']));
$probe('leads -> LeadController@index',
    ($found['leads'] ?? null)?->getController() === 'App\Http\Controllers\Api\LeadController'
    && ($found['leads'] ?? null)?->getAction() === 'index');
$probe('leads/{id} -> LeadController@show',
    ($found['leads/{id}'] ?? null)?->getAction() === 'show');

echo "\nparameter binding\n";
$probe('leads/{id} exposes one parameter',
    ($found['leads/{id}'] ?? null)?->getParameterNames() === ['id'],
    implode(',', ($found['leads/{id}'] ?? null)?->getParameterNames() ?? []));

echo "\n";

$failed = count(array_filter($probes, fn ($p) => !$p));
printf("%d probes, %d failed\n", count($probes), $failed);
exit($failed === 0 ? 0 : 1);
