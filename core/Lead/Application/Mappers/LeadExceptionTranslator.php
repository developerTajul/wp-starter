<?php

declare(strict_types=1);

namespace Core\Lead\Application\Mappers;

use Core\Lead\Domain\Exceptions\InvalidLeadException;
use Core\Shared\Domain\Contracts\TranslatorContract;

/**
 * Turns a refused lead rule into the message a client reads.
 *
 * The domain exception carries a translation key, not wording, so the key is
 * resolved here rather than in each service. Replacement values are themselves
 * translation keys (a status, for instance), so they are resolved before they
 * are substituted into the text.
 */
final class LeadExceptionTranslator
{
    /**
     * @param TranslatorContract $translator
     */
    public function __construct(
        private readonly TranslatorContract $translator,
    ) {}

    /**
     * Resolves the exception's key and its replacement values.
     *
     * @param InvalidLeadException $exception
     *
     * @return string
     */
    public function translate(InvalidLeadException $exception): string
    {
        return $this->translator->translate(
            key: $exception->translationKey,
            replace: $this->translateReplacements($exception),
        );
    }

    /**
     * Resolves each replacement value that is itself a translation key.
     *
     * A value carrying a dot is a key; anything else is already literal text
     * and is left alone.
     *
     * @param InvalidLeadException $exception
     *
     * @return array<string, string>
     */
    public function translateReplacements(InvalidLeadException $exception): array
    {
        return array_map(
            fn(string $value) => str_contains($value, '.')
                ? $this->translator->translate($value)
                : $value,
            $exception->replace,
        );
    }
}