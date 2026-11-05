<?php
declare(strict_types=1);

/**
 * Probe: does the real dashboard screen render, through the real container?
 *
 * Everything is real here — the plugin's base path, the configured providers,
 * the controller, and the view files on disk. The only stubs are WordPress's
 * functions, which is the point: this is the screen a user sees at
 * wp-admin/admin.php?page=wp-mini-laravel, rendered without a WordPress install.
 *
 * Run: php tests/manual/probe-dashboard-view.php
 */

require_once __DIR__ . '/../stubs/wordpress.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/../stubs/wp-classes.php';
require_once __DIR__ . '/../stubs/builtins.php';

use App\Http\Controllers\Admin\DashboardController;
use Framework\Support\ViewRenderer;
use Tests\Support\WpStub;

WpStub::reset();

$root = dirname(__DIR__, 2);
app()->instance('path.base', $root);

// Loaded the way the entry file loads it, so the probe exercises the real
// configuration path rather than a hand-populated store.
Framework\Config\Config::load($root . '/config');

$checks = 0;
$check = static function (string $label, bool $ok) use (&$checks): void {
    $checks++;
    printf("%s %s\n", $ok ? ' ok ' : 'FAIL', $label);
    if (!$ok) {
        exit(1);
    }
};

$renderer = new ViewRenderer();
// Compared through base_path_join, not against a literal: the join uses whichever
// separator the bound base path already uses, which is backslash on Windows.
$check('the default root is the plugin views directory', $renderer->root() === base_path_join($root, 'views'));
$check('layout.php exists', $renderer->exists('layout'));
$check('dashboard.php exists', $renderer->exists('dashboard'));

$controller = new DashboardController($renderer);
$response = $controller->index();
$html = $response->render();

$check('the controller returns a sendable response', $response instanceof Framework\Http\Contracts\ResponseInterface);
$check('the layout wrapped the body', str_contains($html, '<div class="wrap">'));
$check('the title rendered', str_contains($html, '<h1>WP Mini Laravel</h1>'));
$check('the child view rendered into the layout', str_contains($html, 'views/dashboard.php'));
$check('the routes command reached the view', str_contains($html, 'wp wp_mini route:list'));
$check('providers were listed from config', str_contains($html, 'App\Providers\AppServiceProvider'));
$check('no template variable leaked unrendered', !str_contains($html, '$providers') && !str_contains($html, '<?'));

// The escaping contract, against the real renderer rather than a fixture.
$check('e() escaped the command markup context', !str_contains($html, '<script'));
$check('a flash notice would render', str_contains(
    $renderer->render('layout', ['title' => 'T', 'content' => 'C', 'notice' => 'Saved']),
    'notice-success'
));
$check('the error notice is absent when nothing failed', !str_contains($html, 'notice-error'));

printf("\n%d probes passed (%d bytes rendered)\n", $checks, strlen($html));
