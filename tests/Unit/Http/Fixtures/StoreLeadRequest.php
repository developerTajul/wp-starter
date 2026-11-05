<?php
declare(strict_types=1);

namespace Tests\Unit\Http\Fixtures;

use Framework\Http\Requests\FormRequest;

/**
 * A form request with every feature the tests need to exercise, in one place.
 *
 * Several small fixtures were the alternative and were rejected: the interesting
 * behaviour is the *interaction* between rules, message overrides and
 * `authorize()`, and splitting them across four classes would mean four
 * assertions that each prove a quarter of the contract.
 */
final class StoreLeadRequest extends FormRequest
{
    /**
     * Whether `authorize()` should refuse, and what it should say.
     *
     * A public counter rather than a constructor argument: the binder builds this
     * class with no arguments, so anything set at construction would be lost.
     */
    public bool $deny = false;

    public string $denyMessage = '';

    public int $authorizeCalls = 0;

    protected function rules(): array
    {
        return [
            'email' => 'required|email|max:255',
            'name' => 'required|string|min:2',
            'age' => 'nullable|integer|between:18,120',
            'password' => 'nullable|string|min:8|confirmed',
            'tags' => 'array',
            'tags.*' => 'string|max:5',
        ];
    }

    protected function attributes(): array
    {
        return ['email' => 'email address'];
    }

    protected function messages(): array
    {
        return [
            'email.required' => 'We need your email address.',
            'name' => 'Your name is required.',
        ];
    }

    protected function authorize(): bool|string
    {
        $this->authorizeCalls++;

        if (!$this->deny) {
            return true;
        }

        return $this->denyMessage;
    }
}
