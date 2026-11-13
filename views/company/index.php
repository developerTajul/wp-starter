<?php

declare(strict_types=1);

/**
 * The companies list screen body.
 *
 * Handed to `layout` as `$content`, which is where `<div class="wrap">`, the page
 * heading and the notice/error flashes are rendered - so nothing here repeats
 * them. `layout` is this project's answer to `admin-header.php`: WordPress gives
 * a plugin screen no wrapper of its own, and the framework renders one screen by
 * buffering the child and passing the string up.
 *
 * Everything is escaped. A list screen is where a company's own details are
 * printed, and those details are the most user-supplied text on the page.
 * `e()` handles both contexts: `esc_html()` when WordPress is loaded, and
 * `htmlspecialchars(ENT_QUOTES)` when it is not, which is the escape an
 * attribute needs anyway.
 *
 * Every address below arrives from the controller, already resolved: this view
 * names no route and calls no WordPress URL helper, which is what lets it render
 * outside a full admin request. The two exceptions are the closures at the top,
 * which compose a query string onto an address they were handed.
 *
 * The page number travels on the query string, and every link here preserves it.
 * A pager link that dropped it would silently jump to page 1.
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

// The screen slug keeps WordPress on this screen: `admin.php?page=companies`.
// Without it every GET below lands on the dashboard.
$screen = \App\Http\Controllers\Admin\CompanyController::SCREEN_SLUG;

// The columns the table draws, in order, with the heading each carries. One list
// drives the header, the footer and the empty row's colspan, so those three
// cannot drift apart - a colspan that disagrees with the header is the classic
// way a list table ends up with a row that does not line up.
$columns = [
    'name' => 'Name',
    'website' => 'Website',
    'industry' => 'Industry',
    'phone' => 'Phone',
    'created_at' => 'Added',
];

/**
 * The list URL with the given query keys set, merged over what the user is
 * already looking at.
 *
 * Built here rather than with `add_query_arg()`: that function is WordPress's,
 * and a view that cannot render without the CMS is a view the rest of this
 * framework deliberately avoids - `e()` exists for the same reason.
 *
 * The base is the screen's own address, `admin.php?page=companies`, which names
 * `page` exactly once - the parameter WordPress picks the screen from.
 *
 * `paged` is dropped at `1`, which keeps page one at the bare screen address.
 *
 * @param array<string, string|int|null> $overrides
 */
$listUrl = static function (array $overrides) use ($screen): string {
    $base = 'admin.php?page=' . $screen;

    // What the user is looking at now, so a link inherits it and changes only the
    // key it is about: a pager click keeps the page number.
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
 * generator emits `admin.php?page={slug}` from it - so the slug is added here
 * only when that address does not already carry it. The record id is joined with
 * the separator the address actually needs.
 *
 * Getting either wrong still produces a link that works, which is why this needs
 * its own closure rather than being inlined: appending a second `?` gives
 * `?page=companies-edit?page=companies-edit&id=1`, and PHP's parser reads
 * `companies-edit?page=companies-edit` as the value of `page` and finds no `id`
 * at all. The edit screen would open the list instead of the record.
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

<?php // The form keeps the user on this screen: `admin.php?page=companies`. Without
      // it every GET below lands on the dashboard. ?>
<form method="get" action="<?= e('admin.php?page=' . \App\Http\Controllers\Admin\CompanyController::SCREEN_SLUG) ?>">
    <input type="hidden" name="page" value="<?= e($screen) ?>">
</form>

<?php
// The bulk-actions form wraps the table and posts to admin-post.php, which is
// how WordPress addresses a write. `routeAction` is the `action` field WordPress
// dispatches on, and it must be the same string the route registered and the
// nonce was minted for. The redirect returns the user to this screen with its
// filters intact.
//
// The table sits inside a form here, which is what a WordPress list table does:
// the checkboxes have to be submitted with the action, and HTML has no way to
// associate a checkbox with a form it is not inside.
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
                <?php // The select-all box: a label, so clicking the header text toggles it too. ?>
                <td id="cb" class="manage-column column-cb check-column">
                    <label class="screen-reader-text" for="cb-select-all-1">Select all</label>
                    <input id="cb-select-all-1" type="checkbox">
                </td>
                <?php
                // Plain headings, not links: the list has a fixed order and
                // offers no control to change it. WordPress renders `sorted` and
                // an arrow on a clickable column, so carrying those classes here
                // would advertise a control that does not exist.
                ?>
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
                    <?php // The colspan counts the checkbox column too, so it is the
                          // header's own count plus one - never a literal that the
                          // column list could outgrow. ?>
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
                    // The primary column carries the row's identity and its actions.
                    // WordPress collapses a wide table on a narrow screen by hiding
                    // every cell except this one, and marks it with `column-primary`
                    // so the stylesheet knows which that is.
                    //
                    // The row actions are WordPress's own `row-actions` block: hidden
                    // until the row is hovered or focused, which is why they can sit
                    // under the title without cluttering the list. They are wrapped in
                    // a real `<div>`, not printed loose, so their layout is the theme's
                    // to decide rather than this template's.
                    ?>
                    <td class="title column-title has-row-actions column-primary" data-colname="Name">
                        <strong>
                            <a class="row-title"
                               href="<?= e($listUrl([])) ?>"
                               aria-label="<?= e($company->name) ?>"><?= e($company->name) ?></a>
                        </strong>

                        <div class="row-actions">
                            <span class="edit">
                                <?php
                                // The record is named by a query parameter beside the
                                // screen's slug, not by a path under it: WordPress picks
                                // an admin screen by matching `?page=` against the slugs
                                // it was handed at `admin_menu`, and a slug is one string
                                // with no segments.
                                //
                                // Composing that address is `$editUrlFor`'s job - see its
                                // docblock for why it is not done inline here.
                                ?>
                                <a href="<?= e($editUrlFor($company->id)) ?>"
                                   aria-label="Edit <?= e($company->name) ?>">Edit</a> |
                            </span>
                            <span class="trash">
                                <?php
                                // A delete is a write, so it cannot be a link: a GET
                                // that changed stored data would fire on any prefetch,
                                // crawler or reload. WordPress's own row actions post
                                // for exactly this reason.
                                //
                                // A nested form is illegal HTML inside the bulk form
                                // that wraps the table, so this button belongs to a form
                                // of its own - `$deleteFormIdFor($company->id)` - rendered below
                                // the table. HTML5 lets a button name the form it
                                // submits with the `form` attribute, and that
                                // association wins over the form the button sits
                                // inside, which is what keeps a row action out of the
                                // bulk submission.
                                //
                                // The id cannot be a hidden input beside the button:
                                // every row shares this one form, so a hidden per-row
                                // `id` submits once per row and PHP keeps the last of a
                                // repeated name - the bottom row's id - so clicking any
                                // Delete removed the wrong company. Each row's own form
                                // carries that row's id, so exactly one is submitted.
                                //
                                // A browser that ignores `form` submits the bulk form
                                // instead: that is an ordinary bulk request with no row
                                // selected, which deletes nothing rather than the wrong
                                // row.
                                ?>
                                <button type="submit"
                                        form="<?= e($deleteFormIdFor($company->id)) ?>"
                                        class="button-link submitdelete"
                                        aria-label="Delete <?= e($company->name) ?>">Delete</button>
                            </span>
                        </div>

                        <?php
                        // WordPress's responsive toggle. Without it a collapsed table
                        // on a narrow screen offers no way to reveal the other cells.
                        ?>
                        <button type="button" class="toggle-row">
                            <span class="screen-reader-text">Show more details</span>
                        </button>
                    </td>

                    <?php
                    // Printed as text, not linked. A URL a user typed into a form is
                    // not a destination the screen vouches for, and a link here would
                    // be one click away from somewhere this application never checked.
                    ?>
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
            <?php
            // The row count lives here, next to the pager it describes. It is
            // printed only when a page came back: on a failed read `$page` is null
            // and "Showing 0 of 0" would state a fact nobody verified.
            ?>
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
// One form per row, holding only that row's delete. They live here, outside the
// bulk form, because a button may name the form it submits but a form may not
// nest inside another. Each row's Delete button points at its own form with the
// `form` attribute, so a click submits this row's id and nothing else.
//
// The id is a hidden field here rather than on the button, which is safe
// precisely because each id sits in a form of its own: the repeated-name problem
// only arises when several share one submission.
foreach ($companies as $company): ?>
    <form id="<?= e($deleteFormIdFor($company->id)) ?>"
          method="post"
          action="<?= e($deleteFormAction) ?>">
        <input type="hidden" name="action" value="<?= e($deleteRouteAction) ?>">
        <input type="hidden" name="<?= e($deleteIdParam) ?>" value="<?= e($company->id) ?>">
        <?= $deleteNonceField ?>
    </form>
<?php endforeach; ?>
