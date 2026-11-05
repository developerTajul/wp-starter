<?php
declare(strict_types=1);

namespace Tests\Unit\Http\Fixtures;

use Closure;
use Framework\Http\Contracts\Middleware;
use Framework\Http\Contracts\ResponseInterface;
use Framework\Http\Request;
use Framework\Http\Responses\JsonResponse;

/**
 * Answers the request without delegating, which is what a rejected request
 * looks like — and what proves the destination never runs.
 */
final class ShortCircuitingMiddleware implements Middleware
{
    public function __construct(private readonly int $status = 403)
    {
    }

    public function handle(Request $request, Closure $next): ResponseInterface
    {
        return new JsonResponse(['error' => 'short_circuit'], ['status_code' => $this->status]);
    }
}
