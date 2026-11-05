<?php
declare(strict_types=1);

namespace Tests\Unit\Routing\Fixtures;

use Framework\Http\Requests\FormRequest;

/**
 * A REST controller whose action is gated by a form request.
 *
 * The end-to-end fixture for "validation failure on a REST route": the route is
 * registered, WordPress's callback is fired, and what comes back has to be a
 * `WP_Error` rather than a framework response object the REST server would
 * serialize into nonsense.
 */
final class FormRequestController
{
    /**
     * @return array<string, mixed>
     */
    public function store(RestLeadRequest $request): array
    {
        return ['stored' => $request->validated()['email']];
    }
}
