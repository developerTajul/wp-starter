<?php

declare(strict_types=1);

namespace Core\Shared\Domain\Contracts;

/**
 * PORT: slug uniqueness check — the only I/O the shared SlugGenerator
 * utility may perform.
 */
interface SlugCheckerContract
{
    /** Returns true when the slug already exists in the given table. */
    public function exists(string $slug, string $table): bool;
}
