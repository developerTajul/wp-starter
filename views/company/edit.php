<?php
/**
 * The edit-company form body.
 *
 * Handed to `layout` as `$content` by `CompanyController::edit()`, which loads the
 * record first and passes it as `$company`. The `?? ''` on every value is for the
 * rejected-submission path, not for a missing record: the same view is rendered
 * from a failed update, where only the submitted fields are known.
 *
 * WordPress-specific values arrive as variables, the same as the add form, but
 * `$routeAction` differs: an update posts to its own `admin_post_` action,
 * because the two handlers receive different input and one hook could not tell
 * them apart. The carried id is what the update handler addresses the record by.
 *
 * The per-field messages from a rejected submission are read through
 * `field_error()` / `field_class()`, which treat a missing field, an empty
 * message list and a view rendered outside WordPress alike — a first visit needs
 * no guard, and neither does a partial rendered without the error bag.
 *
 * @var string $formAction    Where the form posts — `admin-post.php`.
 * @var string $routeAction   The `admin_post_{action}` name, from the controller.
 * @var string $nonceField    A completed hidden nonce input, already markup.
 * @var string $redirectTo    Where a rejected submission returns to.
 * @var array{id: int, name: string, website: ?string, industry: ?string, phone: ?string, address: ?string}|null $company
 * @var array<string, list<string>> $errors Per-field messages, when a submission was rejected.
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
                <input name="name" id="company-name" type="text" class="<?= field_class('name') ?>"
                       value="<?= old('name', $company['name'] ?? '') ?>" required>
                <?php if ($error = field_error('name')): ?>
                    <p class="error-message" style="color: red; font-size: 0.875rem;"><?= e($error) ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="company-website">Website</label></th>
            <td>
                <input name="website" id="company-website" type="url" class="<?= field_class('website') ?>"
                       value="<?= old('website', $company['website'] ?? '') ?>">
                <p class="description">Optional.</p>
                <?php if ($error = field_error('website')): ?>
                    <p class="error-message" style="color: red; font-size: 0.875rem;"><?= e($error) ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="company-industry">Industry</label></th>
            <td>
                <input name="industry" id="company-industry" type="text" class="<?= field_class('industry') ?>"
                       value="<?= old('industry', $company['industry'] ?? '') ?>">
                <p class="description">Optional.</p>
                <?php if ($error = field_error('industry')): ?>
                    <p class="error-message" style="color: red; font-size: 0.875rem;"><?= e($error) ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="company-phone">Phone</label></th>
            <td>
                <input name="phone" id="company-phone" type="text" class="<?= field_class('phone') ?>"
                       value="<?= old('phone', $company['phone'] ?? '') ?>">
                <p class="description">Optional.</p>
                <?php if ($error = field_error('phone')): ?>
                    <p class="error-message" style="color: red; font-size: 0.875rem;"><?= e($error) ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="company-address">Address</label></th>
            <td>
                <textarea name="address" id="company-address" class="<?= field_class('address') ?>" rows="4"><?= old('address', $company['address'] ?? '') ?></textarea>
                <p class="description">Optional.</p>
                <?php if ($error = field_error('address')): ?>
                    <p class="error-message" style="color: red; font-size: 0.875rem;"><?= e($error) ?></p>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <p class="submit">
        <button type="submit" class="button button-primary">Update company</button>
    </p>
</form>
