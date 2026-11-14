<?php

declare(strict_types=1);

/**
 * The companies list screen body.
 *
 * Handed to `layout` as `$content`, which renders `<div class="wrap">`, the page
 * heading and the flashes.
 *
 * Every address arrives from the controller already resolved: this view names no
 * route and calls no WordPress URL helper, which is what lets it render outside a
 * full admin request. The two closures below compose a query string onto an
 * address they were handed.
 *
 * The page number travels on the query string as `paged`, WordPress's own key for
 * a list table's page. Never `page`: on a wp-admin screen that carries the menu
 * slug WordPress picks the screen from, so `?page=companies` would read as `0` and
 * pin the list to page 1.
 *
 * @var list<\Core\Company\Application\DTOs\Responses\CompanyResponseDTO> $companies
 * @var \Core\Shared\Domain\DataObjects\PaginatedResult|null             $page
 * @var string                                                           $createUrl
 * @var string                                                           $formAction
 * @var string                                                           $routeAction
 * @var string                                                           $nonceField
 * @var string                                                           $redirectTo
 * @var string                                                           $editUrl
 * @var string                                                           $editPageParam
 * @var string                                                           $editPageValue
 * @var string                                                           $editIdParam
 * @var string                                                           $deleteFormAction
 * @var string                                                           $deleteRouteAction
 * @var string                                                           $deleteNonceField
 * @var string                                                           $deleteIdParam
 * @var callable(int): string                                            $deleteFormIdFor
 */

// The screen slug keeps WordPress on this screen, `admin.php?page=companies`.
// Without it every GET below lands on the dashboard.
$screen = \App\Http\Controllers\Admin\CompanyController::SCREEN_SLUG;

// One list drives the header, the footer and the empty row's colspan, so the
// three cannot drift apart.
$columns = [
    'name' => 'Name',
    'website' => 'Website',
    'industry' => 'Industry',
    'phone' => 'Phone',
    'created_at' => 'Added',
];

/**
 * The list URL with the given query keys set, merged over what the user is
 * already looking at, so a pager click keeps the rest of the query string.
 *
 * Built here rather than with `add_query_arg()`: that function is WordPress's,
 * and this view has to render outside the CMS - `e()` exists for the same reason.
 *
 * The base names `page` exactly once, the parameter WordPress picks the screen
 * from. `paged` is dropped at `1`, keeping page one at the bare screen address.
 *
 * @param array<string, string|int|null> $overrides
 */
$listUrl = static function (array $overrides) use ($screen): string {
    $base = 'admin.php?page=' . $screen;

    $query = array_filter(
        ['paged' => $_GET['paged'] ?? null],
        static fn ($value): bool => is_string($value) && $value !== ''
    );

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);

            continue;
        }

        $query[$key] = $value;
    }

    return $query === [] ? $base : $base . '&' . http_build_query($query);
};

/**
 * The edit screen's address for one record.
 *
 * `$editUrl` already names the screen - the route's path is the slug, and the URL
 * generator emits `admin.php?page={slug}` from it - so the slug is added only when
 * that address does not already carry it. The record id joins with the separator
 * the address needs.
 *
 * @param int $companyId The record the link is for.
 */
$editUrlFor = static function (int $companyId) use ($editUrl, $editPageParam, $editPageValue, $editIdParam): string {
    $namesScreen = str_contains($editUrl, $editPageParam . '=');

    $query = $namesScreen
        ? [$editIdParam => $companyId]
        : [$editPageParam => $editPageValue, $editIdParam => $companyId];

    $separator = str_contains($editUrl, '?') ? '&' : '?';

    return $editUrl . $separator . http_build_query($query);
};
?>

<h1 class="wp-heading-inline">Companies</h1>
<a href="<?= e($createUrl) ?>" class="page-title-action">Add New Company</a>
<hr class="wp-header-end">

<?php // The form keeps the user on this screen; without it every GET below lands on the dashboard. ?>
<form method="get" action="<?= e('admin.php?page=' . \App\Http\Controllers\Admin\CompanyController::SCREEN_SLUG) ?>">
    <input type="hidden" name="page" value="<?= e($screen) ?>">
</form>

<?php
// The bulk-actions form wraps the table and posts to admin-post.php, WordPress's
// addressing for a write. `routeAction` is the `action` field WordPress dispatches
// on, and it must match the route and the nonce. The table sits inside the form
// because a checkbox can only be submitted with a form it is inside.
?>
<form method="post" action="<?= e($formAction) ?>">
    <input type="hidden" name="action" value="<?= e($routeAction) ?>">
    <?= $nonceField ?>
    <input type="hidden" name="redirect_to" value="<?= e($redirectTo) ?>">

    <div class="tablenav top">
        <div class="alignleft actions bulkactions">
            <label for="bulk-action-selector-top" class="screen-reader-text">Select bulk action</label>
            <select name="bulk_action" id="bulk-action-selector-top">
                <option value="">Bulk actions</option>
                <option value="delete">Delete</option>
            </select>
            <button type="submit" class="button">Apply</button>
        </div>
        <br class="clear">
    </div>

    <table class="wp-list-table widefat fixed striped table-view-list companies">
        <thead>
            <tr>
                <?php // A label, so clicking the header text toggles the box too. ?>
                <td id="cb" class="manage-column column-cb check-column">
                    <label class="screen-reader-text" for="cb-select-all-1">Select all</label>
                    <input id="cb-select-all-1" type="checkbox">
                </td>
                <?php // Plain headings, not links: the list has a fixed order, and `sorted` with an arrow would advertise a control that does not exist. ?>
                <?php foreach ($columns as $column => $label): ?>
                    <th scope="col" class="manage-column column-<?= e($column) ?><?= $column === 'name' ? ' column-primary' : '' ?>">
                        <?= e($label) ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody id="the-list">
            <?php if ($companies === []): ?>
                <tr class="no-items">
                    <?php // Counts the checkbox column too, so it cannot be outgrown by the column list. ?>
                    <td class="colspanchange" colspan="<?= e((string) (count($columns) + 1)) ?>">
                        No companies found.
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($companies as $company): ?>
                <tr class="iedit author-self level-0">
                    <th scope="row" class="check-column">
                        <label class="screen-reader-text" for="cb-select-<?= e($company->id) ?>">
                            Select <?= e($company->name) ?>
                        </label>
                        <input id="cb-select-<?= e($company->id) ?>"
                               type="checkbox"
                               name="company_ids[]"
                               value="<?= e($company->id) ?>">
                    </th>

                    <?php
                    // WordPress collapses a wide table on a narrow screen by hiding
                    // every cell but this one, so its `column-primary` class marks
                    // which cell the theme keeps.
                    ?>
                    <td class="title column-title has-row-actions column-primary" data-colname="Name">
                        <strong>
                            <a class="row-title"
                               href="<?= e($listUrl([])) ?>"
                               aria-label="<?= e($company->name) ?>"><?= e($company->name) ?></a>
                        </strong>

                        <?php // Hidden until the row is hovered or focused, which is what lets them sit under the title. ?>
                        <div class="row-actions">
                            <span class="edit">
                                <?php
                                // The record is named by a query parameter beside the
                                // screen's slug, not by a path under it: WordPress picks
                                // an admin screen by matching `?page=` against the slugs
                                // it was handed at `admin_menu`, and a slug is one string
                                // with no segments.
                                ?>
                                <a href="<?= e($editUrlFor($company->id)) ?>"
                                   aria-label="Edit <?= e($company->name) ?>">Edit</a> |
                            </span>
                            <span class="trash">
                                <?php
                                // A delete is a write, so it cannot be a link: a GET that
                                // changed stored data would fire on any prefetch, crawler
                                // or reload.
                                //
                                // A nested form is illegal inside the bulk form that wraps
                                // the table, so this button names a form of its own with
                                // HTML5's `form` attribute, rendered below the table. That
                                // association wins over the form the button sits inside.
                                //
                                // The id cannot be a hidden input beside the button: every
                                // row would share the one form, and PHP keeps the last of a
                                // repeated name - so clicking any Delete removed the bottom
                                // row's company. Each row's own form carries that row's id.
                                //
                                // A browser that ignores `form` submits the bulk form, an
                                // ordinary bulk request with no row selected.
                                ?>
                                <button type="submit"
                                        form="<?= e($deleteFormIdFor($company->id)) ?>"
                                        class="button-link submitdelete"
                                        aria-label="Delete <?= e($company->name) ?>">Delete</button>
                            </span>
                        </div>

                        <?php // WordPress's responsive toggle, without which a collapsed table offers no way to reveal the other cells. ?>
                        <button type="button" class="toggle-row">
                            <span class="screen-reader-text">Show more details</span>
                        </button>
                    </td>

                    <?php // Printed as text, not linked: a URL a user typed is not a destination this screen vouches for. ?>
                    <td class="column-website" data-colname="Website">
                        <?php if ($company->website !== null): ?>
                            <?= e($company->website) ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td class="column-industry" data-colname="Industry"><?= $company->industry === null ? '&mdash;' : e($company->industry) ?></td>
                    <td class="column-phone" data-colname="Phone"><?= $company->phone === null ? '&mdash;' : e($company->phone) ?></td>
                    <td class="column-created_at" data-colname="Added"><?= $company->created_at === null ? '&mdash;' : e($company->created_at) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td class="manage-column column-cb check-column"><input type="checkbox"></td>
                <?php foreach ($columns as $column => $label): ?>
                    <th scope="col" class="manage-column column-<?= e($column) ?><?= $column === 'name' ? ' column-primary' : '' ?>">
                        <?= e($label) ?>
                    </th>
                <?php endforeach; ?>
            </tr>
        </tfoot>
    </table>

    <div class="tablenav bottom">
        <div class="alignleft actions bulkactions">
            <label for="bulk-action-selector-bottom" class="screen-reader-text">Select bulk action</label>
            <select name="bulk_action" id="bulk-action-selector-bottom">
                <option value="">Bulk actions</option>
                <option value="delete">Delete</option>
            </select>
            <button type="submit" class="button">Apply</button>
        </div>
        <div class="alignright actions">
            <?php // Only when a page came back: on a failed read `$page` is null and "Showing 0 of 0" would state a fact nobody verified. ?>
            <?php if ($page !== null && $page->total() > 0): ?>
                <span class="displaying-num">
                    <?= e((string) $page->total()) ?> item<?= $page->total() === 1 ? '' : 's' ?>
                </span>
            <?php endif; ?>

            <?php if ($page !== null && $page->lastPage() > 1): ?>
                <span class="pagination-links">
                    <?php if ($page->currentPage() > 1): ?>
                        <a class="first-page button"
                           href="<?= e($listUrl(['paged' => 1])) ?>"
                           aria-label="First page">&laquo;</a>
                        <a class="prev-page button"
                           href="<?= e($listUrl(['paged' => $page->currentPage() - 1])) ?>"
                           aria-label="Previous page">&lsaquo;</a>
                    <?php else: ?>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
                    <?php endif; ?>

                    <span class="paging-input">
                        <span class="tablenav-paging-text">
                            <?= e((string) $page->currentPage()) ?> of
                            <span class="total-pages"><?= e((string) $page->lastPage()) ?></span>
                        </span>
                    </span>

                    <?php if ($page->currentPage() < $page->lastPage()): ?>
                        <a class="next-page button"
                           href="<?= e($listUrl(['paged' => $page->currentPage() + 1])) ?>"
                           aria-label="Next page">&rsaquo;</a>
                        <a class="last-page button"
                           href="<?= e($listUrl(['paged' => $page->lastPage()])) ?>"
                           aria-label="Last page">&raquo;</a>
                    <?php else: ?>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
                        <span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>
                    <?php endif; ?>
                </span>
            <?php endif; ?>
        </div>
        <br class="clear">
    </div>
</form>

<?php
// One form per row, outside the bulk form because a form cannot nest inside
// another. Each row's Delete button points at its own form with the `form`
// attribute, so a click submits this row's id and nothing else. The id is safe as
// a hidden field here precisely because each sits in a form of its own.
foreach ($companies as $company): ?>
    <form id="<?= e($deleteFormIdFor($company->id)) ?>"
          method="post"
          action="<?= e($deleteFormAction) ?>">
        <input type="hidden" name="action" value="<?= e($deleteRouteAction) ?>">
        <input type="hidden" name="<?= e($deleteIdParam) ?>" value="<?= e($company->id) ?>">
        <?= $deleteNonceField ?>
    </form>
<?php endforeach; ?>
