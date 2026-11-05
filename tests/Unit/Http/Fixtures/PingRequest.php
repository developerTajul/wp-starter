<?php
declare(strict_types=1);

namespace Tests\Unit\Http\Fixtures;

use Framework\Http\Requests\FormRequest;

/**
 * The smallest possible form request: one rule, no overrides.
 *
 * Deliberately its own file — see {@see RedirectingRequest} for why a second
 * class in one file is a latent "class not found" rather than a shortcut.
 */
final class PingRequest extends FormRequest
{
    protected function rules(): array
    {
        return ['id' => 'required|integer'];
    }
}
