<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Framework\Http\Requests\FormRequest;

/**
 * Add-company form submission.
 *
 * Holds only the rules. Everything else about a rejected submission, the
 * status code, JSON versus redirect, and how errors and old input survive,
 * is decided by {@see FormRequest} from the request itself.
 *
 * `authorize()` returns true because the route already guards this handler
 * with `Authenticate`, a capability check and a nonce. A create has no record
 * to ask about, so the per-record seam is unused here.
 *
 * No rule names `action`, `_wpnonce` or `redirect_to`, which keeps that
 * plumbing out of {@see FormRequest::validated()}. `id` has no rule on the
 * store path, so a client cannot choose the primary key.
 *
 * Every optional field is explicitly `nullable`: an empty form field arrives
 * as `''`, which satisfies the `string` rule and would be stored as an empty
 * string rather than null.
 */
final class StoreCompanyRequest extends FormRequest
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
            // The only required field; the rest are details that can be
            // filled in later.
            'name' => 'required|string|max:255',

            // `url` rather than `string`, because this value is printed back
            // as a link; a non-URL would become an `<a href>` going nowhere.
            'website' => 'nullable|url',

            'industry' => 'nullable|string|max:100',
            'phone'    => 'nullable|string|max:20',
            'address'  => 'nullable|string',
        ];
    }
}
