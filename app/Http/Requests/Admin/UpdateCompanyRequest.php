<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Framework\Http\Requests\FormRequest;

/**
 * Edit-company form submission.
 *
 * Holds only the rules. Everything else about a rejected submission, the
 * status code, JSON versus redirect, and how errors and old input survive,
 * is decided by {@see FormRequest} from the request itself.
 *
 * `authorize()` returns true because the route already guards this handler
 * with `Authenticate`, a capability check and a nonce. The record's own
 * existence is not decided here either: the command service looks the row up
 * and returns a not-found failure, which keeps one answer to "does this company
 * exist" rather than two that could disagree.
 *
 * No rule names `action`, `_wpnonce` or `redirect_to`, which keeps that
 * plumbing out of {@see FormRequest::validated()}.
 *
 * Every optional field is explicitly `nullable`: an empty form field arrives
 * as `null` once `ConvertEmptyStringsToNull` has run, and that is what the
 * mapper reads as "clear this field" rather than "leave it alone".
 *
 * A field the client did not send is not returned by `validated()` at all. That
 * is load-bearing here: {@see \Core\Company\Application\Mappers\CompanyMapper::mapToUpdateDTO()}
 * treats an absent key as "leave the stored value unchanged" and an explicit
 * null or empty string as "clear it", and {@see FormRequest::validated()} is
 * what keeps those two apart - it walks the rule list and skips any key the
 * request does not carry, so a sparse submission arrives sparse.
 */
final class UpdateCompanyRequest extends FormRequest
{
    protected function authorize(): bool|string
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function rules(): array
    {
        return [
            // The record being changed. It arrives as a form field, not a route
            // placeholder: the update route is an `admin_post_{action}` handler,
            // and an action name cannot carry a value - the same reason the
            // delete handler reads its id from the submission.
            //
            // `min:1` because ids are positive: `0` would name no row, and the
            // service would report not-found for what is really a malformed
            // submission.
            'id' => 'required|integer|min:1',

            // The only required field, as on the store path: a company cannot be
            // left without a name.
            'name' => 'required|string|max:255',

            // `url` rather than `string`, because this value is printed back
            // as a link; a non-URL would become an `<a href>` going nowhere.
            //
            // The scheme list is spelled out rather than left to the rule's
            // default: `url` alone also accepts `ftp://` and `mailto:`, which
            // the entity refuses, so a form that relied on the default would
            // pass validation and then fail the whole update at the domain
            // boundary.
            'website' => 'nullable|url:http,https',

            'industry' => 'nullable|string|max:100',
            'phone'    => 'nullable|string|max:20',
            'address'  => 'nullable|string',
        ];
    }
}
