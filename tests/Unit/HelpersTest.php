<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Covers the path helpers in framework/Support/helpers.php.
 *
 * These helpers read a container binding, and app() holds that container in a
 * function-level static that lives for the whole PHP process. Any test here
 * that depends on the container being in a particular state therefore has to
 * run in its own process, or it would leak bindings into every test after it.
 */
final class HelpersTest extends TestCase
{
    /**
     * base_path() must refuse to guess. A wrong root does not fail loudly at
     * the point of the mistake; it surfaces much later as a stub written to,
     * or a config read from, the wrong directory. Throwing here turns that
     * into an immediate, traceable failure.
     *
     * Runs in a separate process because the assertion is about the state of a
     * freshly-loaded plugin: once anything in this process binds 'path.base'
     * (the entry file does, on boot), the unbound state is unreachable.
     */
    #[RunInSeparateProcess]
    public function test_base_path_throws_when_path_base_is_not_bound(): void
    {
        $this->assertFalse(
            app()->bound('path.base'),
            'Precondition failed: this test needs a container with no path.base binding.'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Base path is not bound in the container.');

        base_path();
    }

    /**
     * The message is the only thing a developer sees when this fires during a
     * boot failure, so pin it against accidental drift into something generic.
     *
     * Note it deliberately does NOT assert the binding key 'path.base': the
     * message names the concept ("Base path"), not the internal key. Asserting
     * the key here would fail today and would not be testing real behaviour.
     */
    #[RunInSeparateProcess]
    public function test_base_path_exception_message_explains_the_missing_binding(): void
    {
        try {
            base_path();
            $this->fail('Expected a RuntimeException from base_path().');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not bound', $e->getMessage());
            $this->assertStringContainsString('container', $e->getMessage());
        }
    }
}
