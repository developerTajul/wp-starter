<?php

declare(strict_types=1);

namespace Core\Shared\Domain\Contracts;

/**
 * PORT: resolves a translation key into the text a user reads.
 *
 * Core never calls the framework's translation helper directly, so this port
 * is what keeps wording out of the Domain and keeps Core runnable under a
 * framework that has no __() of its own.
 */
interface TranslatorContract
{
    /**
     * Returns the text for a key, substituting the given replacements.
     *
     * @param string                $key     e.g. leads.status.contacted
     * @param array<string, string> $replace Values to substitute into the text.
     *
     * @return string Returns the key itself when no translation exists.
     */
    public function translate(string $key, array $replace = []): string;
}
