<?php

declare(strict_types=1);

/**
 * Company module wording, keyed the same way the Laravel build keys it so the
 * file and its keys port unchanged.
 *
 * The keys are flat: `WpTranslator` splits on the first dot only, so
 * `companies.errors.empty_name` is the line named `errors.empty_name` in this
 * file. Nesting the array would need the reader to walk segments.
 *
 * Values a rule fills in use the `:name` placeholder form.
 */

return [
    // Success wording, flashed after a write commits.
    'created' => 'Company created.',
    'updated' => 'Company updated.',
    'deleted' => 'Company deleted.',

    // Failure wording. `errors` is a prefix on the key, not a nested array.
    'errors' => 'The company could not be saved.',
    'errors.company_not_found' => 'That company no longer exists.',
    'errors.create_failed' => 'The company could not be created.',
    'errors.update_failed' => 'The company could not be updated.',
    'errors.delete_failed' => 'The company could not be deleted.',
    'errors.duplicate' => 'A company with those details already exists.',

    // Rule violations raised by the entity.
    'errors.empty_name' => 'A company name is required.',
    'errors.name_too_long' => 'A company name cannot be longer than :max characters.',
    'errors.invalid_website' => 'That website address is not valid.',
    'errors.website_too_long' => 'A website address cannot be longer than :max characters.',
    'errors.industry_too_long' => 'An industry cannot be longer than :max characters.',
    'errors.phone_too_long' => 'A phone number cannot be longer than :max characters.',
    'errors.invalid_per_page' => 'That page size is not allowed.',

    // List and detail screen labels.
    'list.title' => 'Companies',
    'list.empty' => 'No companies yet.',
    'form.create_title' => 'Add a company',
    'form.edit_title' => 'Edit company',
    'form.name' => 'Name',
    'form.website' => 'Website',
    'form.industry' => 'Industry',
    'form.phone' => 'Phone',
    'form.address' => 'Address',
    'form.save' => 'Save company',
];
