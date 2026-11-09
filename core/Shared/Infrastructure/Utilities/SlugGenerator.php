<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Utilities;

use Core\Shared\Domain\Contracts\SlugCheckerContract;

/**
 * Unicode/Bangla-compliant multi-byte slug generation utility.
 *
 * Semantic naming policy (STRICT):
 * - generate()           => pure transformation, NO I/O.
 * - generateUniqueSlug() => additionally enforces uniqueness through the
 *                           injected SlugCheckerContract port (the only I/O
 *                           this utility may perform).
 */
final class SlugGenerator
{
    private const MAX_ATTEMPTS = 100;

    /** VARCHAR(255) schema length constraint for slug columns. */
    private const MAX_LENGTH = 255;

    public function __construct(
        private readonly SlugCheckerContract $slugChecker,
    ) {}

    /**
     * Pure transformation — no I/O. Unicode-aware: natively supports Bangla
     * and other non-Latin scripts.
     * Alias for createBaseSlug() - generates a slug without uniqueness checking.
     */
    public function generate(string $sourceValue): string
    {
        return $this->createBaseSlug($sourceValue);
    }

    /**
     * Pure transformation — no I/O. Unicode-aware: natively supports Bangla
     * and other non-Latin scripts. Alias for generate().
     */
    public function createBaseSlug(string $sourceValue): string
    {
        // Replace every non-letter/non-number run with a dash (Unicode-aware).
        $slug = (string) preg_replace('~[^\pL\pN]+~u', '-', $sourceValue);

        // Collapse duplicate dashes, trim edge dashes, lowercase (multi-byte).
        $slug = (string) preg_replace('~-{2,}~u', '-', $slug);
        $slug = trim($slug, '-');
        $slug = mb_strtolower($slug, 'UTF-8');

        return $this->truncate($slug, self::MAX_LENGTH);
    }

    /**
     * Enforces uniqueness via the injected port, suffixing collisions with a
     * bounded -<n> counter (never an unbounded loop).
     *
     * @throws \RuntimeException when MAX_ATTEMPTS is exhausted
     */
    public function generateUniqueSlug(string $sourceValue, string $table): string
    {
        $baseSlug = $this->generate($sourceValue);

        if (!$this->slugChecker->exists(slug: $baseSlug, table: $table)) {
            return $baseSlug;
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $suffix = '-' . $attempt;

            // Reserve suffix headroom against the 255 VARCHAR length constraint.
            $candidateSlug = $this->truncate(
                $baseSlug,
                self::MAX_LENGTH - mb_strlen($suffix, 'UTF-8'),
            ) . $suffix;

            if (!$this->slugChecker->exists(slug: $candidateSlug, table: $table)) {
                return $candidateSlug;
            }
        }

        throw new \RuntimeException(sprintf(
            'Unable to generate a unique slug for "%s" after %d attempts.',
            $sourceValue,
            self::MAX_ATTEMPTS,
        ));
    }

    /** Safe multi-byte truncation, trimming any trailing dash left behind. */
    private function truncate(string $slug, int $maxLength): string
    {
        if (mb_strlen($slug, 'UTF-8') <= $maxLength) {
            return $slug;
        }

        return rtrim(mb_substr($slug, 0, $maxLength, 'UTF-8'), '-');
    }
}
