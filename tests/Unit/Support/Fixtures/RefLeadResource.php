<?php
declare(strict_types=1);

namespace Tests\Unit\Support\Fixtures;

use Framework\Support\JsonResource;

/**
 * A resource over a verbatim domain object — no conditionals at all.
 *
 * Every field is declared unconditionally, which is what makes it the fixture for
 * the resolution tests: a property read here either resolves or throws, with no
 * `whenLoaded()` in between to turn a miss into an omission.
 */
final class RefLeadResource extends JsonResource
{
    protected function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
        ];
    }
}
