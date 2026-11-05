<?php
declare(strict_types=1);

/**
 * Probe: is the view layer loadable and usable with NO WordPress loaded?
 *
 * The framework's contract is that it can boot outside the CMS. This loads the
 * autoloader alone — no stubs — and exercises the renderer and the escaping
 * helper, which are the two new files that reach for WordPress functions.
 *
 * Run: php tests/manual/probe-viewer-standalone.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$probes = 0;
$check = static function (string $label, bool $ok) use (&$probes): void {
    $probes++;
    printf("%s %s\n", $ok ? ' ok ' : 'FAIL', $label);
    if (!$ok) {
        exit(1);
    }
};

$check('esc_html() is genuinely absent', !function_exists('esc_html'));

$root = sys_get_temp_dir() . '/probe-standalone-' . bin2hex(random_bytes(4));
mkdir($root . '/views', 0755, true);
file_put_contents($root . '/views/index.php', '<?= e($title) ?>');

// The default root is <base>/views, so this is what view() resolves against.
app()->instance('path.base', $root);

$renderer = new Framework\Support\ViewRenderer();

$check('the default root is <base>/views', $renderer->root() === $root . '/views');
$check('a view renders without WordPress', $renderer->render('index', ['title' => 'Ada & Co']) === 'Ada &amp; Co');
$check('e() falls back to htmlspecialchars', e('<b>') === '&lt;b&gt;');
$check('e() escapes quotes without WP', e('"x"') === '&quot;x&quot;');
$check('flash reads are empty, not fatal', $renderer->flash() === []);
$check('errors() is empty, not fatal', $renderer->errors() === []);
$check('old() returns its default, not fatal', $renderer->oldValue('email', 'none') === 'none');
// The container builds its own renderer with no root set, so it defaults to
// <base>/views — which is the documented behaviour, and what this asserts.
$check('view() resolves against the default root', view('index', ['title' => 'x'])->render() === 'x');

@unlink($root . '/views/index.php');
@rmdir($root . '/views');
@rmdir($root);

printf("\n%d probes passed\n", $probes);
