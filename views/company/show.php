<?php
/**
 * The company detail screen body.
 *
 * Handed to `layout` as `$content` by `CompanyController::show()`. The controller
 * passes only the record's id today, so every field below renders its empty
 * placeholder — reading the record is the step that would fill them.
 *
 * @var array<string, mixed>|null $company
 */
?>
<table class="form-table" role="presentation">
    <tr>
        <th scope="row">Name</th>
        <td><strong><?= e($company['name'] ?? '&mdash;') ?></strong></td>
    </tr>
    <tr>
        <th scope="row">Website</th>
        <td>
            <?php if (isset($company['website'])): ?>
                <a href="<?= e($company['website']) ?>"><?= e($company['website']) ?></a>
            <?php else: ?>
                &mdash;
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <th scope="row">Industry</th>
        <td><?= isset($company['industry']) ? e($company['industry']) : '&mdash;' ?></td>
    </tr>
    <tr>
        <th scope="row">Phone</th>
        <td><?= isset($company['phone']) ? e($company['phone']) : '&mdash;' ?></td>
    </tr>
    <tr>
        <th scope="row">Address</th>
        <td><?php
            // `nl2br` after `e()`: the escaping has already neutralised the text,
            // so the only markup introduced is the line breaks this template adds
            // on purpose. The reverse order would let a stored `<br>` through.
            echo isset($company['address']) ? nl2br(e($company['address'])) : '&mdash;';
        ?></td>
    </tr>
</table>
