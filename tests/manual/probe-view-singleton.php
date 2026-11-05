<?php
declare(strict_types=1);

/**
 * Probe: does the singleton binding actually preserve renderer state?
 *
 * The whole justification for binding ViewRenderer as a singleton is that it
 * carries state between resolves — the shared bag and the cached flash. If the
 * container handed back a fresh instance, `share()` in a provider's boot() would
 * silently vanish and a page would read the flash twice. This checks the claim
 * rather than asserting it in a docblock.
 *
 * Run: php tests/manual/probe-view-singleton.php
 */

require_once __DIR__ . '/../stubs/wordpress.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../stubs/wp-classes.php';
require_once __DIR__ . '/../stubs/builtins.php';

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Http\HttpServiceProvider;
use Framework\Support\ViewRenderer;
use Tests\Support\FlashStore;
use Tests\Support\WpStub;

WpStub::reset();

$checks = 0;
$check = static function (string $label, bool $ok) use (&$checks): void {
    $checks++;
    printf("%s %s\n", $ok ? ' ok ' : 'FAIL', $label);
    if (!$ok) {
        exit(1);
    }
};

$container = new Container();
(new HttpServiceProvider($container))->register();

$check('resolves to a renderer', $container->resolve(ViewRenderer::class) instanceof ViewRenderer);
$check('is the same instance twice', $container->resolve(ViewRenderer::class) === $container->resolve(ViewRenderer::class));

// The state that must survive: a shared value set through one resolve.
$container->resolve(ViewRenderer::class)->share('appName', 'WP Mini Laravel');
$check(
    'a shared value survives to the next resolve',
    $container->resolve(ViewRenderer::class)->shared()['appName'] === 'WP Mini Laravel'
);

// The state that must NOT be re-read: the flash is consumed on first read, so a
// second resolve that got a fresh object would come back empty.
$root = sys_get_temp_dir() . '/probe-singleton-' . bin2hex(random_bytes(4));
mkdir($root, 0755, true);
file_put_contents($root . '/page.php', 'ok');
$container->resolve(ViewRenderer::class)->setRoot($root);

FlashStore::write(['notice' => 'Saved']);

$check('the first read sees the flash', $container->resolve(ViewRenderer::class)->flashValue('notice') === 'Saved');
$check('the second read still sees it', $container->resolve(ViewRenderer::class)->flashValue('notice') === 'Saved');
$check('but the transient was consumed', FlashStore::consumed());

// A fresh (non-singleton) renderer is the contrast, and proves the caching —
// not something else — is what made the second read work.
$fresh = new ViewRenderer();
$check('a fresh renderer reads nothing, the flash being spent', $fresh->flash() === []);

@unlink($root . '/page.php');
@rmdir($root);

printf("\n%d probes passed\n", $checks);
