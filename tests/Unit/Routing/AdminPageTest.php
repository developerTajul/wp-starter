<?php
declare(strict_types=1);

namespace Tests\Unit\Routing;

use Framework\Routing\AdminPage;
use Framework\Routing\Route;
use PHPUnit\Framework\TestCase;

/**
 * The typed reader over an admin route's WordPress-specific options.
 *
 * These assertions are about *defaults*, which is where the class earns its
 * place. The adapter, `route:list` and any view that renders a menu all resolve
 * `menu_title` and its fallbacks through here, so a wrong default is wrong in
 * three places at once — and the one that matters is the capability, because
 * `add_menu_page()` with no capability argument at all would make a screen
 * reachable by any logged-in user.
 */
final class AdminPageTest extends TestCase
{
    // -------------------------------------------------------------- defaults

    /**
     * An unset capability must default to `manage_options`, matching WordPress's
     * own default for `add_menu_page()`.
     */
    public function test_the_capability_defaults_to_manage_options(): void
    {
        $page = AdminPage::fromArray('leads');

        $this->assertSame('manage_options', $page->capability());
    }

    /**
     * A title left unset falls back to a readable form of the slug rather than to
     * an empty string — a blank menu entry gives no clue which route made it.
     */
    public function test_the_page_title_falls_back_to_a_readable_slug(): void
    {
        $page = AdminPage::fromArray('lead-reports');

        $this->assertSame('Lead reports', $page->pageTitle());
        $this->assertSame('Lead reports', $page->menuTitle());
    }

    /**
     * A slug that carries no readable words still has to produce *something* —
     * an empty menu label is invisible.
     */
    public function test_an_unreadable_slug_still_produces_a_title(): void
    {
        $this->assertNotSame('', AdminPage::fromArray('---')->pageTitle());
        $this->assertSame('Untitled', AdminPage::fromArray('')->pageTitle());
    }

    /**
     * An unset position is null, not a number. WordPress distinguishes "not given"
     * — where it appends and resolves collisions itself — from a position, and a
     * default integer would pin the entry somewhere the author never asked for.
     */
    public function test_an_unset_position_is_null(): void
    {
        $this->assertNull(AdminPage::fromArray('leads')->position());
    }

    public function test_an_unset_parent_means_top_level(): void
    {
        $page = AdminPage::fromArray('leads');

        $this->assertNull($page->parentSlug());
        $this->assertFalse($page->isSubmenu());
    }

    // --------------------------------------------------------------- fallbacks

    /**
     * A page declaring only a menu title uses it for the heading too — humanizing
     * the slug over the top would ignore a deliberate choice.
     */
    public function test_a_menu_title_alone_supplies_the_page_title(): void
    {
        $page = AdminPage::fromArray('leads', ['menu_title' => 'Leads & Enquiries']);

        $this->assertSame('Leads & Enquiries', $page->pageTitle());
        $this->assertSame('Leads & Enquiries', $page->menuTitle());
    }

    /**
     * An explicit page title does not leak into the menu label, which is often
     * deliberately shorter.
     */
    public function test_the_menu_title_does_not_borrow_an_explicit_page_title(): void
    {
        $page = AdminPage::fromArray('leads', [
            'page_title' => 'All Lead Enquiries',
            'menu_title' => 'Leads',
        ]);

        $this->assertSame('All Lead Enquiries', $page->pageTitle());
        $this->assertSame('Leads', $page->menuTitle());
    }

    // ------------------------------------------------------------- empty values

    /**
     * An option present but empty counts as not given, so a config writing
     * `'icon' => ''` means "no icon" rather than beating the default with a blank.
     */
    public function test_an_empty_option_is_treated_as_absent(): void
    {
        $page = AdminPage::fromArray('leads', [
            'icon' => '',
            'menu_title' => '',
            'capability' => '',
        ]);

        $this->assertFalse($page->has('icon'));
        $this->assertSame('', $page->icon());
        $this->assertSame('manage_options', $page->capability());
        $this->assertSame('Leads', $page->menuTitle());
    }

    // -------------------------------------------------------------- bad values

    /**
     * A position written as a string is accepted when it is a clean integer, and
     * refused otherwise — `(int) 'top'` is 0, which would silently pin the entry
     * to the top of the menu.
     */
    public function test_a_non_numeric_position_is_refused_rather_than_cast(): void
    {
        $this->assertSame(26, AdminPage::fromArray('leads', ['position' => '26'])->position());
        $this->assertNull(AdminPage::fromArray('leads', ['position' => 'top'])->position());
    }

    /**
     * A title given as an array is a mistake, and casting it would label the menu
     * entry literally "Array". The default is used instead.
     */
    public function test_a_non_string_title_falls_back_to_the_default(): void
    {
        $page = AdminPage::fromArray('leads', ['menu_title' => ['not', 'a', 'string']]);

        $this->assertSame('Leads', $page->menuTitle());
    }

    // ------------------------------------------------------------- action name

    /**
     * The action name is absent until the adapter records it at flush time, and
     * this is what `route:list` and a form-rendering view read rather than
     * deriving it a second time.
     */
    public function test_the_action_name_is_absent_until_recorded(): void
    {
        $this->assertNull(AdminPage::fromArray('leads')->action());
        $this->assertSame('leads_store', AdminPage::fromArray('leads', ['action' => 'leads_store'])->action());
    }

    // ------------------------------------------------------------- from a route

    /**
     * The slug comes from the route's path, not from the options bag — the path is
     * what identifies the screen, and duplicating it into the bag would create two
     * places to disagree.
     */
    public function test_it_reads_the_slug_from_the_route_path(): void
    {
        $route = Route::make(['GET'], 'leads')->withOptions(['menu_title' => 'Leads']);

        $page = AdminPage::fromRoute($route);

        $this->assertSame('leads', $page->slug());
        $this->assertSame('Leads', $page->menuTitle());
    }

    /**
     * Options are read, never written back — the route keeps exactly what was
     * declared.
     */
    public function test_it_does_not_modify_the_route(): void
    {
        $route = Route::make(['GET'], 'leads');

        AdminPage::fromRoute($route)->menuTitle();
        AdminPage::fromRoute($route)->capability();

        $this->assertSame([], $route->getOptions());
    }
}
