<?php
/**
 * The add-lead form body.
 *
 * Rendered by App\Http\Controllers\Admin\LeadController::create(). Everything
 * WordPress-specific about this form — where it posts, which action name fires,
 * the nonce, the return address — arrives as a variable, because a template that
 * called `admin_url()` or `wp_nonce_field()` itself could not be rendered outside
 * a wp-admin request. See the controller for which value is which.
 *
 * The field values and their error messages come from the flash bag the framework
 * wrote on the last rejected submission: `old()` repopulates the input, and
 * `$errors` carries the messages. Neither is present on a first visit, which is
 * why both are read defensively rather than with `isset()` on the whole array.
 *
 * @var string $formAction
 * @var string $routeAction
 * @var string $nonceField
 * @var string $redirectTo
 */
?>
<form method="post" action="<?= e($formAction) ?>">
    <?php
    // Emitted unescaped, and deliberately: this is markup `wp_nonce_field()` built,
    // not a value. Escaping it would print the tag rather than a working field.
    ?>
    <?= $nonceField ?>

    <?php
    // The `action` field is what makes WordPress dispatch this to
    // `admin_post_{action}` rather than to the page's own URL, and its value is
    // the same constant the route registered and the nonce was minted for.
    ?>
    <input type="hidden" name="action" value="<?= e($routeAction) ?>">

    <?php
    // Where a rejected submission comes back to. The form posts to admin-post.php,
    // which renders nothing, so without this the browser would land on a blank
    // admin-post response with the errors flashed to a page that cannot show them.
    ?>
    <input type="hidden" name="redirect_to" value="<?= e($redirectTo) ?>">

    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><label for="lead-name">Name</label></th>
            <td>
                <input name="name" id="lead-name" type="text" class="regular-text"
                       value="<?= old('name') ?>" required>
                <?php if (!empty($errors['name'])): ?>
                    <p class="description" style="color:#b32d2e"><?= e($errors['name'][0]) ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="lead-email">Email</label></th>
            <td>
                <input name="email" id="lead-email" type="email" class="regular-text"
                       value="<?= old('email') ?>" required>
                <?php if (!empty($errors['email'])): ?>
                    <p class="description" style="color:#b32d2e"><?= e($errors['email'][0]) ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="lead-phone">Phone</label></th>
            <td>
                <input name="phone" id="lead-phone" type="text" class="regular-text"
                       value="<?= old('phone') ?>">
                <?php if (!empty($errors['phone'])): ?>
                    <p class="description" style="color:#b32d2e"><?= e($errors['phone'][0]) ?></p>
                <?php endif; ?>
                <p class="description">Optional.</p>
            </td>
        </tr>
    </table>

    <p class="submit">
        <button type="submit" class="button button-primary">Add lead</button>
    </p>
</form>
