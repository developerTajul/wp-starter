<?php
declare(strict_types=1);

namespace Tests\Unit\Lifecycle;

use App\Lifecycle\PluginLifecycle;
use App\Lifecycle\UninstallSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

/**
 * The opt-in screen for the uninstall wipe.
 *
 * ## What these tests are actually protecting
 *
 * This field controls the deletion of the site's data, and it has two failure
 * modes that are both silent and both permanent:
 *
 *  - **It writes when it should not.** `save()` runs on every `admin_init`, so a
 *    plain visit to the Reading screen reaches it. If it wrote unconditionally,
 *    merely *looking* at the settings page would turn the wipe off — or, on a
 *    form that posts a different screen's fields, leave a stale `true` behind.
 *  - **It does not write when it should.** The classic checkbox bug: WordPress
 *    does not submit an unchecked box at all, so a save path that only writes
 *    values *present* in `$_POST` cannot record "unchecked". The setting would
 *    read as off on screen and stay `true` in the database, and the next person
 *    to delete the plugin would lose their data to a toggle they had deliberately
 *    turned off.
 *
 * The second is what `register_setting()` would have given, which is why it is
 * not used; `test_it_does_not_route_the_write_through_the_settings_api()` keeps
 * it that way.
 */
final class UninstallSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        WpStub::reset();
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    // ------------------------------------------------------------- registering

    public function test_it_registers_the_field_on_the_reading_screen(): void
    {
        $this->settings()->register();

        $this->assertCount(1, WpStub::$settingsFields, 'Nothing was registered.');

        $field = WpStub::$settingsFields[0];

        $this->assertSame(UninstallSettings::PAGE, $field['page']);
        $this->assertSame(UninstallSettings::SECTION, $field['section']);
        $this->assertIsCallable($field['callback']);
    }

    /**
     * The field's id is the option name, so the `label_for` in its args points at
     * the input the renderer emits. A field registered under a different id gets
     * a label that does not focus the checkbox — a small accessibility failure
     * that is invisible in a screenshot.
     */
    public function test_the_field_id_is_the_option_name_and_matches_its_label(): void
    {
        $this->settings()->register();

        $field = WpStub::$settingsFields[0];
        $option = PluginLifecycle::deleteDataOption();

        $this->assertSame($option, $field['id']);
        $this->assertSame($option, $field['args']['label_for']);

        $this->assertStringContainsString(
            'for="' . $option . '"',
            $this->rendered(),
            'The label does not point at the checkbox input.'
        );
    }

    /**
     * `register_setting()` is the familiar path and the wrong one here: it makes
     * the Settings API own the write, and the Settings API only writes options
     * *present* in the submission — so an unchecked box could never be recorded.
     */
    public function test_it_does_not_route_the_write_through_the_settings_api(): void
    {
        $this->settings()->register();

        $this->assertSame(
            [],
            WpStub::$registeredSettings,
            'register_setting() was used, so unchecking the box could never be saved.'
        );
    }

    // -------------------------------------------------------------- rendering

    public function test_it_renders_unchecked_by_default(): void
    {
        $html = $this->rendered();

        $this->assertStringNotContainsString('checked="checked"', $html, 'The destructive toggle defaulted to on.');
        $this->assertStringContainsString('This cannot be undone.', $html);
    }

    public function test_it_renders_checked_when_the_option_is_on(): void
    {
        PluginLifecycle::setDeleteDataOnUninstall(true);

        $this->assertStringContainsString('checked="checked"', $this->rendered());
    }

    /**
     * The form must carry a nonce, or `save()` would have nothing to verify and
     * the field would be un-saveable — a setting that silently never persists.
     */
    public function test_it_renders_a_nonce_the_save_path_can_verify(): void
    {
        $html = $this->rendered();

        $this->assertStringContainsString('wp_mini_laravel_uninstall_nonce', $html);

        // The rendered nonce is the one that verifies, rather than merely being
        // present — a hardcoded string in a view would satisfy the assertion
        // above while failing this one.
        preg_match('/name="wp_mini_laravel_uninstall_nonce" value="([^"]+)"/', $html, $matches);
        $this->assertNotEmpty($matches, 'No nonce value was rendered.');
        $this->assertNotFalse(wp_verify_nonce($matches[1], UninstallSettings::NONCE_ACTION));
    }

    /**
     * The toggle describes what it does to the plugin's tables and settings, and
     * says plainly that deactivation is unaffected — the one thing a nervous user
     * needs to read before checking it.
     */
    public function test_the_description_names_what_is_deleted_and_what_is_not(): void
    {
        $html = $this->rendered();

        $this->assertStringContainsString('migration', $html);
        $this->assertStringContainsString('Deactivating the plugin never deletes anything', $html);
    }

    // ------------------------------------------------------------------ saving

    /**
     * A plain page load — no nonce — must not write. This is the case that runs
     * on *every* admin request, so getting it wrong would mean the option could
     * never hold `true` for more than one screen view.
     */
    public function test_a_request_with_no_nonce_writes_nothing(): void
    {
        PluginLifecycle::setDeleteDataOnUninstall(true);

        // A GET of the Reading screen: the field is absent from $_POST.
        $this->settings()->save();

        $this->assertTrue(
            PluginLifecycle::shouldDeleteDataOnUninstall(),
            'A plain page load overwrote the stored setting.'
        );
    }

    /**
     * ...and the same for a *post* that carries the field but no valid nonce. The
     * nonce is the only thing standing between this screen and a cross-site
     * request that wipes a site's data.
     */
    public function test_a_submission_with_a_forged_nonce_writes_nothing(): void
    {
        $_POST[PluginLifecycle::deleteDataOption()] = '1';
        $_POST['wp_mini_laravel_uninstall_nonce'] = 'not-a-real-nonce';

        $this->settings()->save();

        $this->assertFalse(PluginLifecycle::shouldDeleteDataOnUninstall());
    }

    /**
     * A nonce issued for a *different* action must not be accepted, even though
     * it is a valid nonce. `wp_verify_nonce()` is bound to its action; a save
     * path that ignored that would accept any nonce the site ever issued.
     */
    public function test_a_nonce_from_another_action_writes_nothing(): void
    {
        $_POST[PluginLifecycle::deleteDataOption()] = '1';
        $_POST['wp_mini_laravel_uninstall_nonce'] = wp_create_nonce('some_other_action');

        $this->settings()->save();

        $this->assertFalse(PluginLifecycle::shouldDeleteDataOnUninstall());
    }

    /**
     * A valid nonce from a user who may not change site options must not write.
     * The nonce proves where the request came from; it says nothing about
     * authority.
     */
    public function test_a_valid_nonce_without_the_capability_writes_nothing(): void
    {
        $this->submit('1');

        WpStub::$capabilities['manage_options'] = false;

        $this->settings()->save();

        $this->assertFalse(PluginLifecycle::shouldDeleteDataOnUninstall());
        $this->assertContains(
            'manage_options',
            WpStub::$capabilitiesAsked,
            'The capability was never checked.'
        );
    }

    public function test_a_valid_submission_that_checks_the_box_writes_true(): void
    {
        $this->submit('1');

        $this->settings()->save();

        $this->assertTrue(PluginLifecycle::shouldDeleteDataOnUninstall());
    }

    /**
     * **The unchecked-checkbox case**, and the reason `register_setting()` is not
     * used. The form posts a nonce but no field, because WordPress does not
     * submit an unchecked box. That must mean `false`, and it must actually be
     * written — a save path that skips absent values leaves the previous `true`
     * in place and the screen showing a toggle that lies.
     */
    public function test_a_valid_submission_with_the_box_unchecked_writes_false(): void
    {
        PluginLifecycle::setDeleteDataOnUninstall(true);
        $this->assertTrue(PluginLifecycle::shouldDeleteDataOnUninstall(), 'Precondition: the option was not on.');

        $_POST['wp_mini_laravel_uninstall_nonce'] = wp_create_nonce(UninstallSettings::NONCE_ACTION);
        // Deliberately no `[option]` key — that is what an unchecked box posts.

        $this->settings()->save();

        $this->assertFalse(
            PluginLifecycle::shouldDeleteDataOnUninstall(),
            'Unchecking the box did not save, so the stored value still says "delete".'
        );
    }

    /**
     * A checkbox that is present but empty — `?option=` in a hand-built request —
     * is not a checked box. Browsers send `'1'` when checked, so anything empty
     * must read as unchecked rather than as a truthy string.
     */
    public function test_an_empty_submitted_value_reads_as_unchecked(): void
    {
        $this->submit('');

        $this->settings()->save();

        $this->assertFalse(PluginLifecycle::shouldDeleteDataOnUninstall());
    }

    /**
     * The option is written with autoload off, matching the reader's comment: it
     * is read once, during an uninstall, and should not be loaded on every page
     * view for the life of the site.
     */
    public function test_the_option_is_stored_without_autoload(): void
    {
        $this->submit('1');

        $this->settings()->save();

        $option = PluginLifecycle::deleteDataOption();

        $this->assertArrayHasKey($option, WpStub::$optionAutoload);
        $this->assertFalse(WpStub::$optionAutoload[$option]);
    }

    /**
     * Saving is idempotent, which is what makes a double submission harmless.
     */
    public function test_saving_an_identical_value_twice_is_harmless(): void
    {
        $this->submit('1');
        $this->settings()->save();
        $this->settings()->save();

        $this->assertTrue(PluginLifecycle::shouldDeleteDataOnUninstall());
    }

    // -------------------------------------------------------------- plumbing

    private function settings(): UninstallSettings
    {
        return new UninstallSettings();
    }

    /**
     * Capture what {@see UninstallSettings::render()} echoes.
     */
    private function rendered(): string
    {
        ob_start();

        try {
            $this->settings()->render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * A submission that would succeed but for whatever the test is varying: a
     * valid nonce and the box checked, as a browser would send.
     */
    private function submit(string $value): void
    {
        $_POST[PluginLifecycle::deleteDataOption()] = $value;
        $_POST['wp_mini_laravel_uninstall_nonce'] = wp_create_nonce(UninstallSettings::NONCE_ACTION);
    }
}
