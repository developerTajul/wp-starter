<?php
declare(strict_types=1);

namespace Tests\Unit\Support\Fixtures;

use Framework\Support\JsonResource;

/**
 * A resource reading a field that does not exist.
 *
 * `emial` — a transposition, which is how this actually happens. The resource
 * is expected to throw rather than emit a null `typo` field, because the null
 * version reaches production and sits there.
 */
final class BrokenResource extends JsonResource
{
    protected function toArray(): array
    {
        return ['id' => $this->id, 'typo' => $this->emial];
    }
}
