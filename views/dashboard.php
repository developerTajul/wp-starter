<?php
/**
 * The dashboard screen body.
 *
 * Rendered by DashboardController and handed to `layout` as `$content`. Note what
 * is *not* here: no `isset()` guards on `$errors` or `$old`, because the renderer
 * omits a flash variable entirely when nothing was flashed, and `!empty()` covers
 * both states with one spelling.
 *
 * @var string $routesCommand
 * @var list<string> $providers
 */
?>
<p>Routing is wired up. This screen was registered by
    <code>WordPressRouterAdapter</code> on <code>admin_menu</code> and dispatched
    through the HTTP pipeline.</p>

<p>The view you are reading was rendered by <code>ViewRenderer</code> from
    <code>views/dashboard.php</code>, wrapped in <code>views/layout.php</code>.</p>

<p>Run <code><?= e($routesCommand) ?></code> to see every registered route.</p>

<h2>Registered providers</h2>
<ul>
    <?php foreach ($providers as $provider): ?>
        <li><code><?= e($provider) ?></code></li>
    <?php endforeach; ?>
</ul>
