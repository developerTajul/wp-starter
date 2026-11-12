<?php
/**
 * The page shell every screen extends.
 *
 * Auto-wrapped by {@see ViewRenderer::make()}: the controller returns the child
 * view and the renderer renders it into `$content`, then renders this layout
 * with `$content` and a `$title` (taken from the view's data, or derived from
 * the view name if absent). A layout is still an ordinary view — buffer a
 * render, pass the string — there is no second template language to learn.
 *
 * `$title` is escaped because it comes from the action, and an unescaped title
 * is the shortest path from a user-supplied name to injected markup.
 *
 * @var string $title
 * @var string $content
 */
?>
<div class="wrap">
    <h1><?= e($title) ?></h1>

    <?php // The summary line a failed validation flashes alongside the field map. ?>
    <?php if (!empty($error)): ?>
        <div class="notice notice-error"><p><?= e($error) ?></p></div>
    <?php endif; ?>

    <?php if (!empty($notice)): ?>
        <div class="notice notice-success is-dismissible"><p><?= e($notice) ?></p></div>
    <?php endif; ?>

    <?= $content ?>
</div>
