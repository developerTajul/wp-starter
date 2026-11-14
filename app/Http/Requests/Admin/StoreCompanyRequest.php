<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Framework\Http\Requests\FormRequest;

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
            'name'     => 'required|string|max:255',
            'website'  => 'nullable|url:http,https',
            'industry' => 'nullable|string|max:100',
            'phone'    => 'nullable|string|max:20|regex:/^[0-9\+\-\s\(\)]+$/',
            'address'  => 'nullable|string',
        ];
    }
}