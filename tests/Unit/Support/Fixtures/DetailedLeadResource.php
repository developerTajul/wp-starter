<?php
declare(strict_types=1);

namespace Tests\Unit\Support\Fixtures;

use Framework\Support\JsonResource;

/**
 * A resource with both kinds of conditional field.
 *
 * `phone` is conditional on the *data* — `whenLoaded()` emits it only when the
 * wrapped entity exposes it. `notes` is conditional on a flag the caller sets.
 * Having both in one resource is what makes the tests show they are genuinely
 * different mechanisms rather than two spellings of one.
 */
final class DetailedLeadResource extends JsonResource
{
    /**
     * A public flag rather than a constructor argument: a resource is usually
     * built by `make()` or by a collection, neither of which can pass one.
     */
    public bool $includeNotes = false;

    protected function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'phone' => $this->whenLoaded('phone'),
            'notes' => $this->when($this->includeNotes, 'Internal note text'),
        ];
    }
}
