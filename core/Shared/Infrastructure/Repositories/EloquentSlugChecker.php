<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Repositories;

use Core\Shared\Domain\Contracts\SlugCheckerContract;
use Illuminate\Support\Facades\DB;

/**
 * ADAPTER: Eloquent/query-builder implementation of the slug uniqueness
 * port. The only place a raw slug-existence query is allowed.
 */
final class EloquentSlugChecker implements SlugCheckerContract
{
    public function exists(string $slug, string $table): bool
    {
        return DB::table($table)
            ->where('slug', $slug)
            ->exists();
    }
}
