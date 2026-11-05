<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Http\Requests\FormRequest;

/**
 * A form request typed against the framework request, for REST dispatch tests.
 */
final class RestLeadRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'email' => 'required|email',
        ];
    }
}
