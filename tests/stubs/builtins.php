<?php
declare(strict_types=1);

/**
 * Test doubles for PHP *builtins* the framework calls.
 *
 * A builtin cannot be redeclared from the global namespace — `function
 * setcookie()` there is a fatal, and the usual `if (!function_exists(...))`
 * guard reads as satisfied when it is not, which is how a stub silently never
 * gets declared. PHP resolves an unqualified call inside a namespace to the
 * namespaced function first, so defining one in the namespace the *caller* is
 * compiled under is what actually works.
 *
 * The caller here is {@see \Framework\Http\Responses\RedirectResponse}, whose
 * `setcookie()` call is unqualified and therefore resolves to the definition
 * below **when this file has been loaded**.
 *
 * That last clause is the whole story: this is a test-only device. Nothing in
 * `framework/`, `app/`, or the plugin bootstrap requires it — only
 * `tests/bootstrap.php` does — so in production the unqualified call resolves
 * to PHP's real `setcookie()` and emits a real response header. The namespaced
 * declaration shadows the builtin only for a process that required this file.
 *
 * Equivalent WordPress stub functions live in `wordpress.php`; this file exists
 * only for the builtins that file cannot define.
 */

namespace Framework\Http\Responses;

use Tests\Support\WpStub;

if (!function_exists(__NAMESPACE__ . '\\setcookie')) {
    /**
     * Captured rather than emitted: the real setcookie() writes a response
     * header, which fails in any test process that has already produced output.
     *
     * @param array<string, mixed> $options
     */
    function setcookie(string $name, string $value = '', array $options = []): bool
    {
        WpStub::$cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];

        return true;
    }
}
