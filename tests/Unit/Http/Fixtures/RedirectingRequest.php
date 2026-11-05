<?php
declare(strict_types=1);

namespace Tests\Unit\Http\Fixtures;

use Framework\Http\Requests\FormRequest;

/**
 * A form request whose failure target is stated, for the redirect-target tests.
 *
 * Deliberately its own file. Several small fixtures in one file look tidier and
 * work only by accident: PSR-4 autoloads a file *by the name of the class it
 * declares*, so a second class in the same file is only loaded once something
 * has already loaded the first. A test referencing it alone then fails with
 * "class not found" — which is exactly what happened to this one and to
 * {@see PingRequest}, visible only when the test was run in isolation.
 */
final class RedirectingRequest extends FormRequest
{
    protected function rules(): array
    {
        return ['email' => 'required|email'];
    }

    protected function redirectTo(): ?string
    {
        return 'https://example.test/somewhere';
    }
}
