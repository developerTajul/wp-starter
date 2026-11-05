<?php
declare(strict_types=1);

namespace Tests\Unit\Support\Fixtures;

use Framework\Support\JsonResource;

/**
 * The resource most of the mapping tests use: three fields over an array.
 *
 * `name` is `whenLoaded()`, which is not decoration — it is what lets one
 * resource be mapped over both a full record and a partial one. `Lead` has no
 * `name` at all, so a resource declaring a bare `$this->name` would throw on
 * every entity it was handed, and the conditional spelling is how a real project
 * expresses a field that some sources carry and others do not.
 *
 * The tests that care about an *unconditional* field use `email`, which every
 * fixture source has.
 */
final class LeadResource extends JsonResource
{
    protected function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->whenLoaded('name'),
        ];
    }
}
