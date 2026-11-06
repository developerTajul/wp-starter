<?php
/**
 * The leads list screen body.
 *
 * Rendered by App\Http\Controllers\Admin\LeadController::index() and handed to
 * `layout` as `$content`, which is where the page heading and the success/error
 * notices are rendered — so nothing here repeats them. Note what is *not* here:
 * no `isset()` guards on `$errors` or `$old`, because the renderer omits a flash
 * variable entirely when nothing was flashed, and `!empty()` covers both states
 * with one spelling.
 *
 * Every value is escaped, including the ones that came from this file's own
 * controller. A list screen is the place a lead's own details are printed, and
 * a lead's details are the most user-supplied text on the whole page.
 *
 * @var list<array<string, mixed>> $leads
 * @var string $createUrl
 */
?>
<p>Every lead this site has captured. There is no leads table yet, so these rows
    are the demo source in <code>LeadController::leads()</code>.</p>

<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th scope="col">Name</th>
            <th scope="col">Email</th>
            <th scope="col">Phone</th>
            <th scope="col">Submitted</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($leads === []): ?>
            <tr>
                <td colspan="4">No leads yet.</td>
            </tr>
        <?php endif; ?>

        <?php foreach ($leads as $lead): ?>
            <tr>
                <td><strong><?= e($lead['name'] ?? '') ?></strong></td>
                <td><a href="mailto:<?= e($lead['email'] ?? '') ?>"><?= e($lead['email'] ?? '') ?></a></td>
                <?php // A row with no phone key is an ordinary lead, not an error —
                      // `isset()` covers both "absent" and "present but null". ?>
                <td><?= isset($lead['phone']) ? e($lead['phone']) : '&mdash;' ?></td>
                <td><time datetime="<?= e($lead['submitted_at'] ?? '') ?>"><?= e($lead['submitted_at'] ?? '') ?></time></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php // The internal `notes` column is absent on purpose — it is gated by its own
      // capability on the REST endpoint, and printing it here would undo that. ?>
<p>
    <a class="button button-primary" href="<?= e($createUrl) ?>">Add a lead</a>
</p>
