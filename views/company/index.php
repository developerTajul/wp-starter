<?php
/**
 * The companies list screen body.
 *
 * Handed to `layout` as `$content`, which is where the page heading and the
 * success/error notices are rendered — so nothing here repeats them. Rendered
 * by `CompanyController::index()`, which wraps it in the layout.
 *
 * Every value is escaped. A list screen is where a company's own details are
 * printed, and those details are the most user-supplied text on the page.
 *
 * @var list<array<string, mixed>> $companies
 */
?>
<p>Every company this site has recorded. The <code>companies</code> table exists,
    but no repository is wired to this screen yet, so the rows rendered here are
    whatever the caller passes in <code>$companies</code>.</p>

<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th scope="col">Name</th>
            <th scope="col">Website</th>
            <th scope="col">Industry</th>
            <th scope="col">Phone</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($companies === []): ?>
            <tr>
                <td colspan="4">No companies yet.</td>
            </tr>
        <?php endif; ?>

        <?php foreach ($companies as $company): ?>
            <tr>
                <td><strong><?= e($company['name'] ?? '') ?></strong></td>
                <?php // A row with no website or phone key is an ordinary company,
                      // not an error — `isset()` covers both "absent" and "present
                      // but null", which is why it is used rather than `!empty()`. ?>
                <td>
                    <?php if (isset($company['website'])): ?>
                        <a href="<?= e($company['website']) ?>"><?= e($company['website']) ?></a>
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
                <td><?= isset($company['industry']) ? e($company['industry']) : '&mdash;' ?></td>
                <td><?= isset($company['phone']) ? e($company['phone']) : '&mdash;' ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
