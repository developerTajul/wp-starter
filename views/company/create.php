<?php
/**
 * The add-company form body.
 *
 * Handed to `layout` as `$content` by `CompanyController::create()`.
 *
 * Everything WordPress-specific about this form — where it posts, which action
 * name fires, the nonce — arrives as a variable rather than being called here.
 * A template that reached for `admin_url()` or `wp_nonce_field()` itself could
 * not be rendered outside a wp-admin request, and the controller is the layer
 * that already knows the action name it shares with the route.
 *
 * The fields are not yet read by anything: `CompanyController::store()` receives
 * the post but the company write path is not wired, so a submission passes its
 * nonce check and then redirects without persisting.
 *
 * @var string $formAction    Where the form posts — `admin-post.php`.
 * @var string $routeAction   The `admin_post_{action}` name, from the controller.
 * @var string $nonceField    A completed hidden nonce input, already markup.
 * @var string $redirectTo    Where a rejected submission returns to.
 * @var array<string, mixed>|null $company
 */
?>
<form method="post" action="<?= e($formAction) ?>">
    <?php
    // Emitted unescaped, and deliberately: this is markup `wp_nonce_field()`
    // built, not a value. Escaping it would print the tag rather than a working
    // field. Its action is the same one the route's `nonce:` middleware verifies.
    ?>
    <?= $nonceField ?>

    <input type="hidden" name="action" value="<?= e($routeAction) ?>">

    <?php
    // The form posts to admin-post.php, which renders nothing; without a return
    // address a rejected submission would land on a blank response.
    ?>
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
        <button type="submit" class="button button-primary">Add company</button>
    </p>
</form>
