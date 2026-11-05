<?php
declare(strict_types=1);

namespace Tests\Unit\Support\Fixtures;

use Framework\Support\JsonResource;

final class CompanyResource extends JsonResource
{
    protected function toArray(): array
    {
        return [
            'name' => $this->name,
            'city' => $this->city,
        ];
    }
}
