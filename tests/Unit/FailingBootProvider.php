<?php
declare(strict_types=1);

namespace Tests\Unit;

use DevLinkStudios\WPServiceContainer\ServiceProvider;
use RuntimeException;

/**
 * A provider whose `boot()` throws, for asserting that a lifecycle hook contains
 * the failure.
 *
 * ## Why this is a standalone class rather than an anonymous one
 *
 * The container keys providers by class name, so an anonymous class would get a
 * generated name that differs per instantiation and cannot be registered by the
 * name `Container::register()` is handed. A named class also makes the failure
 * legible in a stack trace, which matters because the assertion is about a
 * throwable being swallowed — the one place a test most needs to be able to see
 * what was swallowed if the containment is ever removed.
 *
 * ## Why `boot()` and not `register()`
 *
 * `register()` runs when the entry file is included, which happens before
 * `uninstall.php` has decided anything. A provider that throws there fails the
 * include itself, which is a different (and earlier) failure than the one this
 * fixture exists to reproduce. `boot()` runs inside `PluginLifecycle::uninstall()`,
 * after the option gate — which is exactly the frame the caller is supposed to be
 * guarding.
 *
 * The message is deliberately specific: if containment regresses, this string
 * appears in the failure output and names the cause rather than leaving a bare
 * "an exception was thrown".
 */
final class FailingBootProvider extends ServiceProvider
{
    public const MESSAGE = 'the boot provider was deliberately made to fail';

    /**
     * Nothing to bind. The failure is in `boot()`, so `register()` must succeed —
     * otherwise the container refuses to store the provider and `boot()` is never
     * reached, which would make this fixture assert nothing.
     */
    public function register(): void
    {
    }

    public function boot(): void
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
