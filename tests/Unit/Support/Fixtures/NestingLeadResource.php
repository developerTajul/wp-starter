<?php
declare(strict_types=1);

namespace Tests\Unit\Support\Fixtures;

use Framework\Support\JsonResource;

/**
 * A resource that nests another resource.
 *
 * The nested value is a `JsonResource`, not an array — which is what proves a
 * resource resolves its children rather than emitting an object a client cannot
 * read.
 */
final class NestingLeadResource extends JsonResource
{
    protected function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'company' => CompanyResource::make($this->company),
        ];
    }
}
