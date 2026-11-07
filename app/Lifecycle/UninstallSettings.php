<?php
declare(strict_types=1);

namespace App\Lifecycle;

/**
 * The opt-in toggle for "delete my data when this plugin is deleted".
 *
 * ## Why this exists at all
 *
 * {@see PluginLifecycle::shouldDeleteDataOnUninstall()} reads an option that
 * defaults to `false`, which is the correct default and also a dead end: an
 * option nothing can set is an option that is always `false`, and the wipe
 * capability would be unreachable without a `wp option update` on the command
 * line. This is the screen that makes it reachable.
 *
 * ## Why it is filed under the Reading settings
 *
 * WordPress has no general-purpose "plugin settings" screen, so a plugin either
 * builds a top-level menu for one checkbox — which is a poor trade for the user,
 * who now has a menu item that does one thing — or it joins an existing group.
 * `Reading` is the closest fit and the one WordPress's own guidance suggests for
 * a single destructive preference: it is a set-and-forget screen, which is what
 * this is, rather than one a user visits while working.
 *
 * A project that would rather have its own page subclasses this or re-registers
 * the callback on `admin_menu` instead — nothing here depends on which screen it
 * is rendered on.
 *
 * ## The rendering is deliberately dull
 *
 * This controls the deletion of the site's data. It must not look like a
 * setting someone might toggle while exploring: the label says what it does,
 * the description says when it takes effect and that it cannot be undone, and
 * the checkbox is unchecked unless it was explicitly checked. No "recommended"
 * styling, no default-on.
 *
 * ## Why `register_setting()` is not used
 *
 * The familiar path — `register_setting()` plus a `sanitize_callback` — routes
 * the write through the Settings API's own `$_POST` handling, which means the
 * value is only saved when the option is *present* in the submission. For a
 * checkbox that is the classic bug: unchecked boxes are not submitted at all, so
 * unchecking this one would leave the previous `true` in place and the next
 * person to delete the plugin would lose their data to a setting the screen
 * shows as off.
 *
 * So the nonce is verified and the value is written through
 * {@see PluginLifecycle::setDeleteDataOnUninstall()}, whose `(bool)` cast is what
 * makes "absent from the submission" mean false. That is the whole reason the
 * setter takes a `bool` rather than the raw request value.
 */
final class UninstallSettings
{
    /**
     * The option-group name, and the `page` the form posts to.
     */
    public const PAGE = 'reading';

    /**
     * The settings section this field is added to.
     */
    public const SECTION = 'default';

    /**
     * The nonce action verified before any write.
     */
    public const NONCE_ACTION = 'wp_mini_laravel_uninstall_settings';

    /**
     * Register the settings field.
     *
     * Hooked on `admin_init` from {@see \App\Providers\LifecycleServiceProvider}
     * — see that class. `admin_init` rather than `admin_menu` because the
     * Settings API's registration calls are for the *form*, and `admin_menu` is
     * for building screens; hooking registration there works today and is not
     * what the API docs describe.
     */
    public function register(): void
    {
        if (!function_exists('add_settings_field')) {
            return;
        }

        add_settings_field(
            $this->option(),
            'Delete data on uninstall',
            [$this, 'render'],
            self::PAGE,
            self::SECTION,
            ['label_for' => $this->option()]
        );
    }

    /**
     * Save the field, before the screen renders.
     *
     * Hooked on `admin_init` ahead of {@see register()}, because a write and a
     * render in the same request would show the previous value back to the user.
     */
    public function save(): void
    {
        if (!function_exists('wp_verify_nonce') || !function_exists('current_user_can')) {
            return;
        }

        // Absent nonce means this is an ordinary page load, not a submission —
        // the same request `register()` is rendering into. Returning here is what
        // keeps a plain visit to the Reading screen from writing `false` over a
        // `true` the user set earlier.
        $nonce = $_POST['wp_mini_laravel_uninstall_nonce'] ?? null;

        if (!is_string($nonce) || wp_verify_nonce($nonce, self::NONCE_ACTION) === false) {
            return;
        }

        // The same capability WordPress requires to save the Reading screen the
        // field is rendered on. Checked here as well as being implied by the
        // nonce, because a nonce proves the request came from this screen and
        // says nothing about whether the caller may change a site-wide option.
        if (!current_user_can('manage_options')) {
            return;
        }

        // Read straight off `$_POST` rather than through a WP helper, and this is
        // a real constraint rather than a shortcut: WordPress runs
        // `wp_magic_quotes()` at bootstrap, so `$_POST` values arrive slashed,
        // and the framework's `SanitizeInput` middleware does not run on an
        // admin screen. A checkbox value needs no sanitizing beyond the cast —
        // anything non-empty is "checked", anything absent is "not" — so the
        // setter's `(bool)` is the whole validation.
        PluginLifecycle::setDeleteDataOnUninstall(
            isset($_POST[$this->option()]) && $_POST[$this->option()] !== ''
        );
    }

    /**
     * Render the checkbox.
     *
     * `checked()` and `esc_attr()` are guarded rather than assumed: this method
     * is a callback WordPress invokes, and the guards keep it callable from a
     * test that renders it without a full admin context.
     */
    public function render(): void
    {
        $option = $this->option();
        $checked = PluginLifecycle::shouldDeleteDataOnUninstall();

        $nonce = function_exists('wp_nonce_field')
            ? wp_nonce_field(self::NONCE_ACTION, 'wp_mini_laravel_uninstall_nonce', true, false)
            : '';

        printf(
            '%s<label for="%s"><input type="checkbox" id="%s" name="%s" value="1" %s /> '
            . 'Delete all of this plugin\'s tables and stored settings when the plugin is deleted.</label>'
            . '<p class="description">Off by default. When on, deleting the plugin runs every migration\'s '
            . '<code>down()</code> and removes the migration history. <strong>This cannot be undone.</strong> '
            . 'Deactivating the plugin never deletes anything, whatever this is set to.</p>',
            $nonce,
            function_exists('esc_attr') ? esc_attr($option) : $option,
            function_exists('esc_attr') ? esc_attr($option) : $option,
            function_exists('esc_attr') ? esc_attr($option) : $option,
            $checked ? 'checked="checked"' : ''
        );
    }

    /**
     * The option name, derived the same way the reader derives it.
     */
    private function option(): string
    {
        return PluginLifecycle::deleteDataOption();
    }
}
