<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Storage;

use Core\Shared\Domain\Contracts\FileUploaderContract;
use Core\Shared\Infrastructure\Utilities\SlugGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ADAPTER: Laravel storage implementation of the media upload/delete port.
 * Stores files on the configured disk and returns the persisted relative path.
 */
final class LaravelFileUploader implements FileUploaderContract
{
    private const MAX_ATTEMPTS = 100;

    public function __construct(
        private readonly SlugGenerator $slugGenerator,
        private readonly string $disk = 'public'
    ) {}

    /**
     * Stores the file with defensive uniqueness logic.
     *
     * @param mixed $file expected to be an Illuminate\Http\UploadedFile
     *                   (the port keeps the type framework-agnostic)
     */
    public function upload(mixed $file, string $directory): string
    {
        if (!$file instanceof UploadedFile) {
            throw new \InvalidArgumentException(
                'LaravelFileUploader expects an instance of ' . UploadedFile::class . '.',
            );
        }

        $originalFilename = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();

        $baseName = $this->slugGenerator->createBaseSlug(pathinfo($originalFilename, PATHINFO_FILENAME));

        if ($baseName === '') {
            $baseName = 'media-' . uniqid();
        }

        $fileName = $baseName . '.' . $extension;
        $isUnique = false;

        // Step 1: Base check outside the loop (directory-aware)
        if (!Storage::disk($this->disk)->exists($directory . '/' . $fileName)) {
            $isUnique = true;
        } else {
            // Step 2: Suffix check inside bounded loop
            for ($counter = 1; $counter <= self::MAX_ATTEMPTS; $counter++) {
                $fileName = $baseName . '-' . $counter . '.' . $extension;

                if (!Storage::disk($this->disk)->exists($directory . '/' . $fileName)) {
                    $isUnique = true;
                    break;
                }
            }
        }

        // Step 3: Fail-safe fallback
        if (!$isUnique) {
            $fileName = $baseName . '-' . Str::uuid() . '.' . $extension;
        }

        $storedPath = $file->storeAs($directory, $fileName, $this->disk);

        if ($storedPath === false) {
            throw new \RuntimeException(
                sprintf('Failed to store uploaded file in directory "%s".', $directory),
            );
        }

        return $storedPath;
    }

    public function delete(string $path): bool
    {
        if (Storage::disk($this->disk)->exists($path)) {
            return Storage::disk($this->disk)->delete($path);
        }
        return false;
    }
}