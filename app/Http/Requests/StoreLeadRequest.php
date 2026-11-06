<?php
declare(strict_types=1);

namespace App\Http\Requests;

use Framework\Http\Requests\FormRequest;

/**
 * The add-lead form submission.
 *
 * ## What this class is responsible for
 *
 * Only the *rules*. Everything else about a rejected submission — which status
 * code, whether the client gets JSON or a redirect, where the browser lands, how
 * the errors and the old input survive the redirect — belongs to
 * {@see FormRequest}, and it decides all of it from the request: a `fetch()` post
 * gets a 422 with a field map, a browser gets a redirect back to the form. This
 * class does not have to know which one happened.
 *
 * ## Why `redirect_to` is what sends the browser back to the form
 *
 * The form lives on `admin.php?page=leads-create`, but it posts to
 * `admin-post.php` — a different URL, and one that renders no page. So "back" is
 * not the referer and not the request URI. The form carries its own return
 * address in a `redirect_to` field, which is the mechanism
 * {@see FormRequest::redirectTarget()} looks for first after an explicit
 * `redirectTo()`; see the form view, which sets it to the screen the fields are
 * actually on.
 *
 * ## What the rules do not include
 *
 * There is no rule for `action`, `_wpnonce` or `redirect_to`. That is what keeps
 * them out of {@see FormRequest::validated()}: a field with no rule is not
 * returned, so the controller cannot accidentally treat the form's own plumbing
 * as lead data, and a client cannot smuggle a field in by inventing a name the
 * rules never named.
 */
final class StoreLeadRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    protected function rules(): array
    {
        return [
            'name'  => 'required|string|max:100',
            'email' => 'required|email|max:255',
            // Optional, and therefore `nullable`: a lead without a phone number is
            // an ordinary lead, not a validation failure.
            'phone' => 'nullable|string|max:40',
        ];
    }

    /**
     * The human-readable names used in the default messages.
     *
     * Only where the rule engine's own phrasing reads badly — "The email must be
     * a valid email address" is the kind of message that makes a form look
     * broken rather than informative.
     *
     * @return array<string, string>
     */
    protected function attributes(): array
    {
        return [
            'email' => 'email address',
        ];
    }
}
