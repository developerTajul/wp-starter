<?php
declare(strict_types=1);

namespace Tests\Unit\Lifecycle;

use App\Lifecycle\ActivationFailureNotice;
use App\Lifecycle\PluginLifecycle;
use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\Lifecycle\MigratesOnActivation;
use Framework\Database\Lifecycle\RemovesPluginData;
use Framework\Database\Migrator;
use Framework\Database\Schema\Schema;
use Framework\Database\Storage\MigrationStorageInterface;
use Framework\Database\Storage\WpMigrationStorage;
use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\ThrowingMigration;
use Tests\Support\FakeWpdb;
use Tests\Support\MigrationWorkspace;
use Tests\Support\SchemaMigration;
use Tests\Support\WpStub;

/**
 * What an operator is told when an activation fails to build the schema.
 *
 * ## The failure this is guarding
 *
 * The bug report that produced this class described a plugin that "activates
 * cleanly" while creating no tables. The hook-placement bug behind it is covered
 * by {@see \Tests\Unit\ActivationBootOrderTest}; this file covers the reason it
 * went unnoticed for as long as it did — an activation that fails its migrations
 * is *indistinguishable from one that succeeds*, because WordPress reports
 * success either way and the only record is a line in a log file.
 *
 * So the tests here are about visibility: that a failure is recorded, that it is
 * rendered to someone who can act on it, that the message says which migration
 * failed, and that it stops being shown once the problem is fixed.
 */
final class ActivationFailureNoticeTest extends TestCase
{
    private MigrationWorkspace $workspace;
    private FakeWpdb $db;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->workspace = new MigrationWorkspace();
        $this->db = new FakeWpdb();

        Schema::useAdapter(new WpDatabaseAdapter($this->db));
    }

    protected function tearDown(): void
    {
        Schema::stopPretending();
        Schema::useAdapter(null);

        $this->workspace->cleanup();
    }

    // -------------------------------------------------------- option naming

    /**
     * The option is prefixed from config, like every other key the plugin
     * stores — a hardcoded name would survive a rebrand in the writer and not in
     * the reader, so the notice would simply stop appearing on a rebranded site.
     */
    #[RunInSeparateProcess]
    public function test_the_option_name_is_derived_from_the_app_prefix(): void
    {
        \Framework\Config\Config::flush();
        \Framework\Config\Config::load(__DIR__ . '/../../Fixtures/config');

        $this->assertSame('acme_', config('app.prefix'), 'Precondition: the fixture did not load.');
        $this->assertSame('acme_activation_failure', (new ActivationFailureNotice())->option());
    }

    // ------------------------------------------------------------- recording

    public function test_an_activation_failure_is_recorded_and_rendered(): void
    {
        $this->failingActivation();

        $html = $this->rendered();

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('could not be prepared', $html);
    }

    /**
     * The message must name the migration that failed. "Something went wrong" is
     * a notice an operator cannot act on; the identifier is what lets them open
     * the file and look.
     */
    public function test_the_notice_names_the_migration_that_failed(): void
    {
        $this->failingActivation();

        $this->assertStringContainsString(
            \Tests\Support\ThrowingMigration::MESSAGE,
            $this->rendered(),
            'The notice does not say what failed.'
        );
    }

    /**
     * A healthy install shows nothing. A notice that appears unconditionally
     * would be worse than none: operators learn to dismiss it, and the one time
     * it matters it is already being ignored.
     */
    public function test_nothing_is_rendered_when_no_failure_was_recorded(): void
    {
        $this->assertSame('', $this->rendered());
    }

    // ---------------------------------------------------------- permissions

    /**
     * Only someone who could act on it sees a schema failure. An author editing
     * a post cannot run a migration, and a notice telling them to is noise.
     */
    public function test_the_notice_is_hidden_from_users_who_cannot_act_on_it(): void
    {
        $this->failingActivation();

        WpStub::$capabilities['activate_plugins'] = false;

        $this->assertSame(
            '',
            $this->rendered(),
            'A user without activate_plugins was shown a schema failure they cannot fix.'
        );
    }

    /**
     * The capability checked is the one that governs activating plugins — not
     * `manage_options`, which is the uninstall toggle's and would be the wrong
     * question here.
     */
    public function test_the_capability_checked_is_the_one_that_governs_activation(): void
    {
        $this->failingActivation();

        $this->rendered();

        $this->assertContains('activate_plugins', WpStub::$capabilitiesAsked);
    }

    // ------------------------------------------------------------- escaping

    /**
     * The message reaches the notice from an exception, which may carry a SQL
     * fragment — and it is echoed into an admin page. Unescaped, a migration that
     * failed on a table named `"><script>` would be an XSS in the admin.
     */
    public function test_the_message_is_escaped_before_it_is_rendered(): void
    {
        $notice = new ActivationFailureNotice();

        $notice->record(new \RuntimeException('<script>alert(1)</script> & "quoted"'));

        $html = $this->rendered($notice);

        $this->assertStringNotContainsString('<script>', $html, 'An unescaped message reached the admin page.');
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
    }

    // --------------------------------------------------------------- clearing

    /**
     * A later successful activation clears it — the notice describes a condition
     * that has stopped being true.
     */
    public function test_a_successful_activation_clears_an_earlier_failure(): void
    {
        // First: a migration that throws.
        $this->workspace->write('2026_11_05_100000_create_users_table', ThrowingMigration::class);
        $this->activate();
        $this->assertNotSame('', $this->rendered(), 'Precondition: no failure was recorded.');

        // Then: the problem is fixed and the plugin is activated again.
        $notice = new ActivationFailureNotice();
        $notice->clear();

        $this->assertSame('', $this->rendered(), 'The notice survived the fix.');
    }

    /**
     * ...and a clean activation on an *already up-to-date* schema clears it too.
     *
     * This is the case a naive implementation gets wrong: keying the clear on
     * "did this run apply anything" would leave the notice up forever once the
     * schema was current, because a healthy re-activation applies nothing.
     */
    public function test_a_clean_activation_with_nothing_pending_still_clears_a_failure(): void
    {
        // A schema that migrates cleanly, so the activation is a no-op.
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        (new ActivationFailureNotice())->record(new \RuntimeException('stale failure'));

        $this->assertNotSame('', $this->rendered(), 'Precondition: no stale failure was recorded.');

        $this->activate();

        $this->assertSame(
            '',
            $this->rendered(),
            'A successful no-op activation left a stale failure notice on screen.'
        );
    }

    /**
     * A *failed* activation must not clear a previous failure — it replaces it.
     * Clearing on the way through would mean the notice only ever appeared when
     * the plugin had previously been healthy, which is exactly backwards.
     */
    public function test_a_failed_activation_does_not_clear_the_notice(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', ThrowingMigration::class);

        $this->activate();

        $this->assertNotSame('', $this->rendered(), 'A failing activation cleared its own notice.');
    }

    // -------------------------------------------------------------- plumbing

    /**
     * Activate through the real {@see PluginLifecycle}, with the notice bound as
     * the reporter — the same composition `LifecycleServiceProvider` performs.
     */
    private function activate(): void
    {
        $container = $this->container();

        (new PluginLifecycle())->activate($container);
    }

    /**
     * Run an activation whose migration throws, leaving the failure recorded.
     */
    private function failingActivation(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', ThrowingMigration::class);

        $this->activate();
    }

    /**
     * Capture what the notice renders under the default capability.
     */
    private function rendered(?ActivationFailureNotice $notice = null): string
    {
        $notice ??= new ActivationFailureNotice();

        ob_start();

        try {
            $notice->render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * A container shaped like `app()`'s, minus the two self-bindings the notice
     * does not need.
     */
    private function container(): Container
    {
        $container = new Container();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Container::class, $container);

        $storage = new WpMigrationStorage($this->db);
        $container->instance(MigrationStorageInterface::class, $storage);

        $container->singleton(Migrator::class, fn (): Migrator => $this->migrator());
        $container->singleton(ActivationFailureNotice::class);
        $container->singleton(
            MigratesOnActivation::class,
            static fn ($c): MigratesOnActivation => new MigratesOnActivation(
                $c->resolve(Migrator::class),
                static fn (\Throwable $e) => $c->resolve(ActivationFailureNotice::class)->record($e),
            )
        );

        return $container;
    }

    private function migrator(): Migrator
    {
        return new Migrator(
            $this->workspace->path(),
            new WpDatabaseAdapter($this->db),
            new WpMigrationStorage($this->db),
        );
    }
}
