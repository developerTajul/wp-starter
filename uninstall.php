<?php
declare(strict_types=1);

/**
 * Uninstall: remove the plugin's data, but only when the site owner asked.
 *
 * ## Why a file rather than register_uninstall_hook()
 *
 * Both are supported and they are not equivalent. `register_uninstall_hook()`
 * writes the callback into the `uninstall_plugins` option as a serialized
 * entry keyed by plugin basename, which means the registration lives *in the
 * database* — the very thing being torn down. Deleting the plugin leaves the
 * entry behind, pointing at a class the autoloader can no longer find, and
 * WordPress will error on it the next time anything reads the option. There is
 * also a version of the problem that bites sooner: the hook stores a class or
 * function name only, so a plugin that keeps its logic in a namespaced class
 * cannot use it at all without a global function to bounce through.
 *
 * `uninstall.php` is the other form, and it stores nothing. WordPress includes
 * this file once, during the delete request, having already defined
 * `WP_UNINSTALL_PLUGIN`. That constant is the security boundary and it is
 * checked below — without it, anyone who could reach this path directly over
 * HTTP would be triggering a data wipe, because the file has no other guard and
 * WordPress's own check is only that it included the file itself.
 *
 * ## Why the plugin is bootstrapped by hand
 *
 * `uninstall.php` runs in a request where the plugin is still on disk but is
 * *not* loaded: WordPress does not include a plugin's entry file when it is
 * being deleted, and `plugins_loaded` never fires. So the container in `app()`
 * is either empty or — on the multisite and "delete while active" paths — holds
 * a state from earlier in the same request. Both are handled the same way:
 * include the entry file if it has not run, which binds the paths, loads the
 * config and registers the providers, and then let
 * {@see \App\Lifecycle\PluginLifecycle::uninstall()} boot the container itself
 * once it knows the wipe has actually been requested.
 *
 * Requiring the entry file rather than repeating its bootstrap here is
 * deliberate: the boot order in it is documented as load-bearing (`path.base`
 * before config, config before providers, providers before boot), and a second
 * copy of that sequence in this file is a second place for it to be wrong. It is
 * also safe to `require` in the delete-while-active case, because the entry
 * file's own `WP_MINI_LARAVEL_BOOTED` guard returns immediately when it has
 * already run.
 *
 * ## What it does about failure
 *
 * Nothing escapes. WordPress is mid-deletion here; an uncaught throwable would
 * abort it and leave the plugin's files half-removed with the reason visible
 * only if the host happens to have `display_errors` on.
 * {@see \Framework\Database\Lifecycle\RemovesPluginData::runWithoutThrowing()}
 * reports through the error log instead, and the plugin is deleted either way —
 * with the operator told, in the log, which tables survived.
 */

// The guard WordPress sets by defining this before it includes the file. Checked
// first, before anything else can run, because everything below it is
// destructive.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$entry = __DIR__ . '/wp-mini-laravel.php';

// A missing entry file means the plugin's files are already gone — a partial
// deletion, or an uninstall that ran after a manual file removal. There is
// nothing to resolve a migrator through, so the data stays; silently, because
// there is no one this file could tell.
if (!is_file($entry) || !is_readable($entry)) {
    return;
}

require_once $entry;

// The entry file returns early — without binding anything — when `vendor/` is
// missing, and `uninstall.php` has no admin notice to fall back on. Checking a
// helper the framework's own bootstrap defines is the cheapest way to tell the
// two apart: if `app()` is not defined, the plugin never got far enough to be
// able to delete anything.
if (!function_exists('app')) {
    return;
}

app()->resolve(\App\Lifecycle\PluginLifecycle::class)->uninstall(app());
