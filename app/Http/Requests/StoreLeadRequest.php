<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Framework\Http\Requests\FormRequest;

/**
 * Add-lead form submission.
 *
 * Holds only the rules. Everything else about a rejected submission, the
 * status code, JSON versus redirect, and how errors and old input survive,
 * is decided by {@see FormRequest} from the request itself.
 *
 * The form lives on `admin.php?page=leads-create` but posts to
 * `admin-post.php`, which renders no page, so "back" is not the referer or
 * the request URI. The form's own `redirect_to` field carries the return
 * address, and {@see FormRequest::redirectTarget()} reads it first after an
 * explicit `redirectTo()`.
 *
 * No rule names `action`, `_wpnonce` or `redirect_to`, which keeps that
 * plumbing out of {@see FormRequest::validated()}.
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
            // Optional, so `nullable`: a lead without a phone number is an
            // ordinary lead, not a validation failure.
            'phone' => 'nullable|string|max:40',
        ];
    }

    /**
     * Human-readable names used in the default messages.
     *
     * Only where the rule engine's own phrasing reads badly.
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
