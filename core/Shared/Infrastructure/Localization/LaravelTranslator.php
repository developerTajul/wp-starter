<?php

declare(strict_types=1);

namespace Core\Shared\Infrastructure\Localization;

use Core\Shared\Domain\Contracts\TranslatorContract;

/**
 * Laravel-backed translation. The only place the framework's translation
 * helper is called.
 *
 * @implements TranslatorContract
 */
final class LaravelTranslator implements TranslatorContract
{
    /**
     * @param string                $key
     * @param array<string, string> $replace
     *
     * @return string
     */
    public function translate(string $key, array $replace = []): string
    {
        return __($key, $replace);
    }
}
