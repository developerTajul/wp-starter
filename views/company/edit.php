<?php
/**
 * The edit-company form body.
 *
 * Handed to `layout` as `$content` by `CompanyController::edit()`. The controller
 * passes only the record's id today, so the fields below render empty rather than
 * populated — loading the record is the step that would fill them.
 *
 * WordPress-specific values arrive as variables, the same as the add form, but
 * `$routeAction` differs: an update posts to its own `admin_post_` action,
 * because the two handlers receive different input and one hook could not tell
 * them apart. The carried id is what the update handler addresses the record by.
 *
 * @var string $formAction    Where the form posts — `admin-post.php`.
 * @var string $routeAction   The `admin_post_{action}` name, from the controller.
 * @var string $nonceField    A completed hidden nonce input, already markup.
 * @var string $redirectTo    Where a rejected submission returns to.
 * @var array<string, mixed>|null $company
 */
?>
<form method="post" action="<?= e($formAction) ?>">
    <?= $nonceField ?>

    <input type="hidden" name="action" value="<?= e($routeAction) ?>">
    <input type="hidden" name="id" value="<?= e((string) ($company['id'] ?? '')) ?>">
    <input type="hidden" name="redirect_to" value="<?= e($redirectTo) ?>">

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="company-name">Name</label></th>
            <td>
                <input name="name" id="company-name" type="text" class="regular-text"
                       value="<?= old('name', $company['name'] ?? '') ?>" required>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="company-website">Website</label></th>
            <td>
                <input name="website" id="company-website" type="url" class="regular-text"
                       value="<?= old('website', $company['website'] ?? '') ?>">
                <p class="description">Optional.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="company-industry">Industry</label></th>
            <td>
                <input name="industry" id="company-industry" type="text" class="regular-text"
                       value="<?= old('industry', $company['industry'] ?? '') ?>">
                <p class="description">Optional.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="company-phone">Phone</label></th>
            <td>
                <input name="phone" id="company-phone" type="text" class="regular-text"
                       value="<?= old('phone', $company['phone'] ?? '') ?>">
                <p class="description">Optional.</p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="company-address">Address</label></th>
            <td>
                <textarea name="address" id="company-address" class="regular-text" rows="4"><?= old('address', $company['address'] ?? '') ?></textarea>
                <p class="description">Optional.</p>
            </td>
        </tr>
    </table>

    <p class="submit">
        <button type="submit" class="button button-primary">Update company</button>
    </p>
</form>
