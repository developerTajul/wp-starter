<?php
declare(strict_types=1);

namespace Tests\Unit\Support\Fixtures;

use Framework\Support\JsonResource;

/**
 * A resource whose mapping throws something that is not a LogicException.
 *
 * The distinction matters to a caller that catches around `resolve()`: an absent
 * field is a `LogicException` and a deliberate signal, while this is a real
 * failure and has to keep propagating as itself.
 */
final class ThrowingResource extends JsonResource
{
    protected function toArray(): array
    {
        throw new \RuntimeException('mapping blew up');
    }
}
