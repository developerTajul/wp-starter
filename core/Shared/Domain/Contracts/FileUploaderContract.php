<?php

declare(strict_types=1);

namespace Core\Shared\Domain\Contracts;

/**
 * PORT: media upload/delete. Framework-agnostic — the adapter decides
 * what the uploaded-file payload concretely is.
 */
interface FileUploaderContract
{
    /**
     * Stores the file and returns the persisted relative path.
     *
     * @param mixed $file delivery-provided upload payload (kept framework-agnostic)
     */
    public function upload(mixed $file, string $directory): string;

    public function delete(string $path): bool;
}
