<?php
/**
 * The page shell every screen extends.
 *
 * A layout is an ordinary view: a controller renders a child into a string and
 * hands it here as `$content`. There is no template inheritance because PHP
 * already has a mechanism for it — buffer a render, pass the string — and a
 * second one would be a second thing to learn.
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
