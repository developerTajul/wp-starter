<?php
declare(strict_types=1);

namespace Tests\Unit\Support\Fixtures;

use Framework\Support\JsonResource;

/**
 * A resource with no `id`, for the collections that key by it.
 *
 * An item whose id cannot be recovered has to be skipped rather than keyed by an
 * empty string, or several such items collapse into one.
 */
final class AnonymousResource extends JsonResource
{
    protected function toArray(): array
    {
        return ['value' => $this->value];
    }
}
