<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/tests/bootstrap.php';

use Tests\Support\WpStub;
use Framework\Routing\Router;
use Framework\Routing\RouteResolver;

WpStub::reset();

$container = new DevLinkStudios\WPServiceContainer\Container();
$router = new Router($container, new RouteResolver($container));
$registrar = $router->registrar();
$loader = require dirname(__DIR__, 2) . '/routes/api.php';
$loader($registrar);
$router->registerRoutes('wp-mini/v1');

echo "\nwhat an ANONYMOUS caller is permitted\n";
foreach (WpStub::$registeredRoutes['wp-mini/v1'] as $reg) {
    WpStub::$currentUserCan = false;
    $request = new WP_REST_Request();
    $result = ($reg['permission_callback'])($request);
    printf("  %-22s => %s\n", $reg['route'], var_export($result === true, true));
}
echo "\n";
