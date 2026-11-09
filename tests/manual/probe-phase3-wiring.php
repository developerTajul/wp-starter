<?php
declare(strict_types=1);

/**
 * Probe: are the Phase 2 pieces actually resolvable from the container?
 *
 * Run: php tests/manual/probe-phase3-wiring.php
 *
 * The unit tests construct these classes directly, which proves the classes work
 * but not that anything in a real boot can *reach* them. The pipeline resolves
 * middleware by class name from the container and the adapters resolve the Handler
 * the same way, so a missing binding is a feature that is present in the tree and
 * absent at runtime.
 */

require __DIR__ . '/../bootstrap.php';

use Core\Shared\Domain\Contracts\RateLimiterContract;
use DevLinkStudios\WPServiceContainer\Container;
use Framework\Config\Config;
use Framework\Http\Exceptions\Handler;
use Framework\Http\Middleware\MiddlewareAliases;
use Framework\Http\Middleware\ThrottleRequests;

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    if ($ok) {
        echo "  PASS  {$label}\n";

        return;
    }

    $failures++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

$container = new Container();

echo "1. Service providers register the bindings\n";
foreach ([
    new \Framework\Http\HttpServiceProvider($container),
    new \App\Providers\RepositoryServiceProvider($container),
] as $provider) {
    try {
        $provider->register();
    } catch (Throwable $e) {
        check($provider::class . ' registered', false, $e::class . ': ' . $e->getMessage());
    }
}

echo "\n2. Bindings resolve\n";
check('Handler is bound', class_exists(Handler::class) && $container->bound(Handler::class));
check('RateLimiterContract is bound', $container->bound(RateLimiterContract::class));

try {
    $handler = $container->resolve(Handler::class);
    check('Handler resolves', $handler instanceof Handler, get_debug_type($handler));
} catch (Throwable $e) {
    check('Handler resolves', false, $e::class . ': ' . $e->getMessage());
}

try {
    $limiter = $container->resolve(RateLimiterContract::class);
    check('RateLimiter resolves', $limiter instanceof RateLimiterContract, get_debug_type($limiter));
} catch (Throwable $e) {
    check('RateLimiter resolves', false, $e::class . ': ' . $e->getMessage());
}

echo "\n3. The throttle alias points at a loadable class\n";
$aliases = new MiddlewareAliases();
$all = $aliases->all();
check('throttle alias present', $aliases->has('throttle'), implode(', ', array_keys($all)));
check(
    'throttle alias resolves to ThrottleRequests',
    $aliases->resolve('throttle') === ThrottleRequests::class,
    $aliases->resolve('throttle')
);

echo "\n4. ThrottleRequests is constructible the way the pipeline builds it\n";
try {
    // The pipeline resolves by class name, so the concrete class has to be
    // auto-wirable from the RateLimiterContract binding alone.
    $container->instance(RateLimiterContract::class, $container->resolve(RateLimiterContract::class));
    $middleware = $container->resolve(ThrottleRequests::class);
    check('ThrottleRequests resolves', $middleware instanceof ThrottleRequests, get_debug_type($middleware));
} catch (Throwable $e) {
    check('ThrottleRequests resolves', false, $e::class . ': ' . $e->getMessage());
}

echo "\n5. The migration creates the limiter table\n";
$migration = 'database/migrations/2026_11_08_120000_create_rate_limits_table.php';
check('migration file exists', is_file($migration), $migration);

echo "\n";
if ($failures === 0) {
    echo "ALL PROBES PASSED\n";
    exit(0);
}

echo "{$failures} PROBE(S) FAILED\n";
exit(1);
