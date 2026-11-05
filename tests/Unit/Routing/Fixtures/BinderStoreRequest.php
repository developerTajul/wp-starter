<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Http\Requests\FormRequest;

/**
 * The form request the binder tests type-hint.
 *
 * Kept separate from the one in `Tests\Unit\Http\Fixtures` because the two test
 * different contracts — this one just needs rules that can pass or fail on
 * command, with nothing else to distract from whether the action ran.
 */
final class BinderStoreRequest extends FormRequest
{
    protected function rules(): array
    {
        return [
            'email' => 'required|email',
            'name' => 'required|string',
        ];
    }
}
