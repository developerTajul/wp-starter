<?php
declare(strict_types=1);

namespace Tests\Unit\Lifecycle;

use Framework\Lifecycle\PluginLifecycle;
use DevLinkStudios\WPServiceContainer\Container;
use DevLinkStudios\WPServiceContainer\Contracts\ContainerInterface;
use Framework\Database\Adapters\WpDatabaseAdapter;
use Framework\Database\Lifecycle\MigratesOnActivation;
use Framework\Database\Lifecycle\RemovesPluginData;
use Framework\Database\Migrator;
use Framework\Database\Schema\Schema;
use Framework\Database\Storage\MigrationStorageInterface;
use Framework\Config\Config;
use Framework\Database\Storage\WpMigrationStorage;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWpdb;
use Tests\Support\MigrationWorkspace;
use Tests\Support\SchemaMigration;
use Tests\Support\WpStub;

/**
 * The plugin's lifecycle policy, driven through a real container.
 *
 * The container is built the way `app()` builds it — with the two self-bindings
 * — and the `Migrator` is overridden to point at a temporary migration directory
 * and a fake database, which is also how a project with a different layout would
 * override it.
 */
final class PluginLifecycleTest extends TestCase
{
    private MigrationWorkspace $workspace;
    private FakeWpdb $db;
    private Container $container;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->workspace = new MigrationWorkspace();
        $this->db = new FakeWpdb();
        $this->container = $this->container();

        Schema::useAdapter(new WpDatabaseAdapter($this->db));
    }

    protected function tearDown(): void
    {
        Schema::stopPretending();
        Schema::useAdapter(null);

        $this->workspace->cleanup();
    }

    // ---------------------------------------------------------------- option

    /**
     * The option name is derived from `app.prefix`, which is the value the
     * rebrander rewrites. A hardcoded literal would be rewritten in the settings
     * screen and not in the reader, so a rebranded plugin's toggle would control
     * nothing.
     *
     * ## Why this loads a fixture rather than reading the shipped config
     *
     * The shipped `app.prefix` is `wp_mini_laravel_`, which is *also* the
     * library's default — so a test reading it cannot tell a derived name from a
     * hardcoded one, and an assertion written against the resolved value passes
     * either way. The fixture's `acme_` shares no substring with the default,
     * which is what makes "did this come from config, or is it a literal?" an
     * answerable question. The same trap the fixture's own docblock describes.
     *
     * In a separate process, and reset afterwards, because `Config` is
     * process-wide and there is no setter to put it back — a merge of `acme_`
     * left behind would rewrite the prefix for every test that runs after this
     * one, which is precisely the order dependency `Config` has no setter to
     * create.
     */
    #[RunInSeparateProcess]
    public function test_the_option_name_is_derived_from_the_app_prefix(): void
    {
        Config::flush();
        Config::load(__DIR__ . '/../../Fixtures/config');

        $this->assertSame('acme_', config('app.prefix'), 'Precondition: the fixture did not load.');
        $this->assertSame('acme_delete_data_on_uninstall', PluginLifecycle::deleteDataOption());
    }

    /**
     * The default is `true`: deleting the plugin is the consent.
     *
     * ## Why this is the assertion, and why it is the one that matters
     *
     * The option used to default to `false`, and the effect on a real site was
     * that the plugin's files were deleted while its tables stayed — because the
     * only thing that ever wrote the option was a checkbox on the Reading screen
     * that most operators never opened. WordPress's own prompt ("Are you sure
     * you want to delete X and its data?") is the consent, given at the moment of
     * deletion by the person doing the deleting, and an install where nobody
     * touched the setting is the ordinary case rather than an unusual one.
     *
     * So an absent option — the state of every fresh install — must mean the
     * wipe runs. This test is what fails if the default ever drifts back.
     */
    public function test_data_is_deleted_when_the_option_was_never_set(): void
    {
        $this->assertArrayNotHasKey(
            PluginLifecycle::deleteDataOption(),
            WpStub::$options,
            'Precondition: something already wrote the option.'
        );

        $this->assertTrue(
            PluginLifecycle::shouldDeleteDataOnUninstall(),
            'The default kept a site\'s data, so deleting the plugin leaves its tables behind.'
        );
    }

    /**
     * A stored value can arrive as `'1'`, `1` or `true` depending on who wrote
     * it, and all three mean yes; `false` and its spellings are the opt-out.
     */
    public function test_the_setting_is_read_as_a_bool_whatever_was_stored(): void
    {
        foreach (['1', 1, true, 'yes'] as $stored) {
            WpStub::$options[PluginLifecycle::deleteDataOption()] = $stored;

            $this->assertTrue(
                PluginLifecycle::shouldDeleteDataOnUninstall(),
                'A stored value of ' . var_export($stored, true) . ' should read as "delete".'
            );
        }

        foreach (['', '0', 0, false] as $stored) {
            WpStub::$options[PluginLifecycle::deleteDataOption()] = $stored;

            $this->assertFalse(
                PluginLifecycle::shouldDeleteDataOnUninstall(),
                'A stored value of ' . var_export($stored, true) . ' should read as "keep".'
            );
        }
    }

    /**
     * The setter and the reader agree, and the write does not autoload — this is
     * read once, during an uninstall.
     */
    public function test_the_setter_round_trips_through_wordpress_options(): void
    {
        PluginLifecycle::setDeleteDataOnUninstall(true);
        $this->assertTrue(PluginLifecycle::shouldDeleteDataOnUninstall());

        PluginLifecycle::setDeleteDataOnUninstall(false);
        $this->assertFalse(PluginLifecycle::shouldDeleteDataOnUninstall());
    }

    // ------------------------------------------------------------ activation

    public function test_activation_migrates(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);

        $this->lifecycle()->activate($this->container);

        $this->assertTrue($this->db->tableExists('wp_users'));
    }

    /**
     * Activation boots the container. This is the case WordPress reaches on
     * every activation: `activate_{plugin}` is fired from `activate_plugin()`
     * during `admin_init`, so the plugin's own `plugins_loaded` — registered in
     * the entry file — has already passed, and without an explicit boot a
     * provider's `boot()` would never run.
     */
    public function test_activation_boots_the_container(): void
    {
        $this->assertFalse($this->container->isBooted(), 'Precondition: the container was already booted.');

        $this->lifecycle()->activate($this->container);

        $this->assertTrue($this->container->isBooted());
    }

    /**
     * ...and booting twice is harmless, because `boot()` is idempotent. A
     * re-activation must not double-register anything.
     */
    public function test_activation_is_repeatable(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);

        $lifecycle = $this->lifecycle();
        $lifecycle->activate($this->container);
        $lifecycle->activate($this->container);

        $this->assertCount(1, $this->db->rows('wp_plugin_migrations'));
    }

    // ---------------------------------------------------------- deactivation

    /**
     * Deactivation flushes rewrite rules and touches nothing else.
     */
    public function test_deactivation_flushes_rewrite_rules(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->lifecycle()->activate($this->container);

        $rewrite = $this->installRewriteStub();

        $this->lifecycle()->deactivate($this->container);

        $this->assertSame([false], $rewrite->flushes, 'Deactivation should flush rules without the hard rewrite.');
    }

    /**
     * The policy that gives the requirement its name: deactivating must never
     * delete anything. Everything stored survives, which is what makes
     * reactivation cheap — and what a user expects from a pause button.
     */
    public function test_deactivation_never_deletes_data(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->lifecycle()->activate($this->container);

        $this->db->query("INSERT INTO `wp_users` (`email`) VALUES ('someone@example.test')");
        $this->installRewriteStub();

        $this->lifecycle()->deactivate($this->container);

        $this->assertTrue($this->db->tableExists('wp_users'), 'Deactivation dropped a table.');
        $this->assertTrue($this->db->tableExists('wp_plugin_migrations'), 'Deactivation dropped the ledger.');
        $this->assertCount(1, $this->db->rows('wp_users'), 'Deactivation deleted rows.');
        $this->assertCount(1, $this->db->rows('wp_plugin_migrations'));
    }

    /**
     * Deactivation does not boot the container — the rewrite flush needs no
     * services, and booting would resolve every provider's `boot()` for nothing.
     */
    public function test_deactivation_does_not_boot_the_container(): void
    {
        $this->installRewriteStub();

        $this->lifecycle()->deactivate($this->container);

        $this->assertFalse($this->container->isBooted());
    }

    /**
     * A request with no rewrite engine — `uninstall.php`, a bootstrap context —
     * must not fatal.
     */
    public function test_deactivation_tolerates_a_missing_rewrite_engine(): void
    {
        unset($GLOBALS['wp_rewrite']);

        $this->lifecycle()->deactivate($this->container);

        $this->assertFalse($this->container->isBooted());
    }

    // ------------------------------------------------------------- uninstall

    /**
     * The opt-out: an operator who unticked the box keeps everything — and
     * nothing is touched at all, not even the container, since the work behind it
     * would be for a decision already made.
     */
    public function test_uninstall_keeps_everything_when_the_operator_opted_out(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->lifecycle()->activate($this->container);

        PluginLifecycle::setDeleteDataOnUninstall(false);

        $container = $this->container();
        $removed = $this->lifecycle()->uninstall($container);

        $this->assertFalse($removed);
        $this->assertTrue($this->db->tableExists('wp_users'), 'The operator opted out and a table was dropped.');
        $this->assertTrue($this->db->tableExists('wp_plugin_migrations'));
        $this->assertFalse($container->isBooted(), 'The container was booted for a wipe that did not happen.');
    }

    /**
     * **The default path, and the bug this whole change is about.**
     *
     * Nothing writes the option — the state of every install where nobody has
     * opened the Reading screen, which is nearly all of them. WordPress asked
     * "delete X and its data?", the operator said yes, and the tables must go.
     * Before this, they did not: the file was deleted and the schema survived.
     */
    public function test_uninstall_removes_everything_when_the_option_was_never_set(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->lifecycle()->activate($this->container);

        $this->assertArrayNotHasKey(
            PluginLifecycle::deleteDataOption(),
            WpStub::$options,
            'Precondition: something already wrote the option.'
        );

        $this->assertTrue($this->lifecycle()->uninstall($this->container()));
        $this->assertFalse(
            $this->db->tableExists('wp_users'),
            'An uninstall with no stored preference left the plugin\'s tables behind.'
        );
        $this->assertFalse($this->db->tableExists('wp_plugin_migrations'));
    }

    /**
     * ...and when the option is explicitly on, which is the same outcome by the
     * other route.
     */
    public function test_uninstall_removes_everything_when_the_option_is_on(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->lifecycle()->activate($this->container);

        PluginLifecycle::setDeleteDataOnUninstall(true);

        $this->assertTrue($this->lifecycle()->uninstall($this->container()));
        $this->assertFalse($this->db->tableExists('wp_users'));
        $this->assertFalse($this->db->tableExists('wp_plugin_migrations'));
    }

    /**
     * A failure is reported, not thrown: `uninstall.php` runs while WordPress is
     * deleting the plugin, and an escaping throwable would abort that deletion
     * with the reason visible only if `display_errors` happens to be on.
     */
    public function test_uninstall_reports_a_failure_rather_than_throwing(): void
    {
        $this->workspace->write('2026_11_05_100000_create_users_table', SchemaMigration::class);
        $this->lifecycle()->activate($this->container);

        PluginLifecycle::setDeleteDataOnUninstall(true);

        // The migration file is removed but its ledger row survives, which is
        // the state `rollbackAll()` refuses to guess at.
        $this->workspace->delete('2026_11_05_100000_create_users_table');

        $this->assertFalse($this->lifecycle()->uninstall($this->container()));
    }

    // -------------------------------------------------------------- plumbing

    /**
     * A container shaped like `app()`'s: the two self-bindings the provider
     * type-hints need, the migration bindings, and the test's own migrator.
     */
    private function container(): Container
    {
        $container = new Container();
        $container->instance(ContainerInterface::class, $container);
        $container->instance(Container::class, $container);

        // The storage is bound to a *shared* instance rather than built twice, so
        // the migrator and the removal service are reading and writing the same
        // ledger — which is what the real provider guarantees by binding the
        // interface as a singleton.
        $storage = new WpMigrationStorage($this->db);
        $container->instance(MigrationStorageInterface::class, $storage);

        $container->singleton(Migrator::class, fn (): Migrator => $this->migrator());
        $container->singleton(
            MigratesOnActivation::class,
            static fn ($c): MigratesOnActivation => new MigratesOnActivation($c->resolve(Migrator::class))
        );
        $container->singleton(
            RemovesPluginData::class,
            static fn ($c): RemovesPluginData => new RemovesPluginData(
                $c->resolve(Migrator::class),
                $c->resolve(MigrationStorageInterface::class)
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

    private function lifecycle(): PluginLifecycle
    {
        return new PluginLifecycle();
    }

    /**
     * Put a recording `WP_Rewrite` in the global, as WordPress does.
     */
    private function installRewriteStub(): \WP_Rewrite
    {
        $rewrite = new \WP_Rewrite();
        $GLOBALS['wp_rewrite'] = $rewrite;

        return $rewrite;
    }
}
