<?php
declare(strict_types=1);

namespace Tests\Unit\Support\Fixtures;

use Framework\Support\JsonResource;

/**
 * A resource reading fields through accessors rather than properties.
 *
 * `label` resolves through `Lead::label()` and `active` through
 * `Lead::isActive()` — the two accessor spellings the resolution order supports.
 */
final class AccessorLeadResource extends JsonResource
{
    protected function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'label' => $this->label,
            'active' => $this->active,
        ];
    }
}
