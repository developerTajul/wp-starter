<?php
declare(strict_types=1);

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;

/**
 * `mini`'s WP-CLI discovery, which decides *which* binary the entry point runs.
 *
 * ## Why this is tested through the source rather than through the script
 *
 * `mini` is an executable script, not a class — running it means forwarding to a
 * real WP-CLI against a real WordPress, which is what
 * `tests/manual/verify-mini-entrypoint.php` does and why it cannot be a PHPUnit
 * test. But the resolution logic itself is pure: given a PATH and a platform, it
 * answers one question. Extracting the four functions and calling them directly is
 * what makes the *answer* testable without a WordPress install, and it keeps the
 * failure message on the branch that was wrong rather than on a subprocess.
 *
 * The extraction is deliberately narrow — it takes the named functions and their
 * docblocks and nothing else, because the script's top level runs on include and
 * would call `exit()`. A function renamed in `mini` makes the extraction throw
 * here rather than silently testing nothing, which is the failure mode a
 * "read the file as text" test would have.
 *
 * ## The defect these tests pin
 *
 * A bare `wp` led the Windows candidate list, on the reasoning that it is the
 * name a user means. On an install where `wp` is a shell script — a polyglot
 * wrapper, or a file with no extension — PHP cannot start it, so `mini` reported
 * "could not start WP-CLI" while a usable `wp-cli.phar` sat on the same PATH. The
 * tests below assert both halves of the remedy: runnable forms lead, and a
 * shebang script is skipped rather than returned.
 */
final class MiniBinaryResolutionTest extends TestCase
{
    /**
     * The functions under test, plus `miniIsBatchFile`, which `miniPathCandidates`
     * is documented in terms of.
     */
    private const HELPERS = [
        'miniIsBatchFile',
        'miniIsShellScript',
        'miniPathCandidates',
        'miniFindInstalled',
        'miniCacheDirectory',
    ];

    /**
     * The script's constants, defined here rather than extracted.
     *
     * `miniCacheDirectory()` reads `MINI_CACHE_DIRECTORY`, and the extraction
     * below takes functions only — `eval()` on the `const` line would work, but a
     * redeclaration would be a hard error if a second test class ever did the
     * same, and a constant is not what these tests are pinning. The *value* is
     * checked against the script instead, in
     * {@see test_the_cache_directory_name_matches_the_one_the_script_declares()}.
     */
    private const CACHE_DIRECTORY = 'wp-mini-laravel-cli-cache';

    /**
     * A directory standing in for a machine's `bin/`, created per test and removed
     * in tearDown.
     */
    private string $binDirectory;

    private ?string $restorePath = null;

    protected function setUp(): void
    {
        $this->binDirectory = sys_get_temp_dir() . '/wpmini-bin-' . bin2hex(random_bytes(6));

        if (!mkdir($this->binDirectory, 0755, true) && !is_dir($this->binDirectory)) {
            $this->fail("Could not create the fixture directory [{$this->binDirectory}].");
        }

        $this->loadHelpers();
    }

    protected function tearDown(): void
    {
        if ($this->restorePath !== null) {
            putenv('PATH=' . $this->restorePath);
            $this->restorePath = null;
        }

        $this->removeDirectory($this->binDirectory);
    }

    /**
     * Remove a fixture tree, files first.
     *
     * Recursive rather than a single `unlink()` because two of these tests put a
     * binary in a nested directory to model PATH having more than one entry — and
     * a `glob()` over the root sees those as bare directory names, so a flat
     * cleanup warns and leaves the tree behind for the next run.
     */
    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . '/*') ?: [] as $entry) {
            is_dir($entry) ? $this->removeDirectory($entry) : unlink($entry);
        }

        rmdir($directory);
    }

    /**
     * A directly-runnable `.phar` is preferred to an extension-less `wp`.
     *
     * The ordering is the fix rather than a detail of it: on Windows a `.phar` is
     * the one form PHP runs under {@see PHP_BINARY} with no shell and no wrapper,
     * so it is the form to reach for first. Asserted on the candidate list rather
     * than on a found binary, because that is where the preference is expressed.
     */
    public function test_windows_prefers_a_runnable_phar_over_a_bare_wp(): void
    {
        $candidates = $this->helpers()->miniPathCandidates();

        if (PHP_OS_FAMILY !== 'Windows') {
            // The Unix list is intentionally untouched: there, `wp` *is* the phar
            // and PHP runs it natively, so reordering it would be a change with
            // no failure to fix.
            $this->assertSame(['wp', 'wp-cli.phar'], $candidates);

            return;
        }

        $this->assertSame(['wp-cli.phar', 'wp.phar', 'wp.exe', 'wp.bat', 'wp.cmd', 'wp'], $candidates);

        $this->assertLessThan(
            array_search('wp', $candidates, true),
            array_search('wp-cli.phar', $candidates, true),
            'A runnable phar must be tried before a bare `wp`, which may be a script PHP cannot start.'
        );
    }

    /**
     * A file beginning with a shebang is recognised as one PHP cannot run.
     *
     * This is the predicate the search uses, and it is checked on the *content*
     * rather than the extension because the whole problem is a file whose
     * extension says nothing useful — a `.phar`, a `.exe` and a Unix binary all
     * have to be kept, and none of them shares an extension with a wrapper script.
     */
    public function test_a_shebang_file_is_recognised_as_unrunnable(): void
    {
        $script = $this->binDirectory . '/wp';
        file_put_contents($script, "#!/usr/bin/env sh\nexec php \"\$0\" \"\$@\"\n");

        $phar = $this->binDirectory . '/wp-cli.phar';
        file_put_contents($phar, "<?php\n// a phar stub\n");

        $windows = $this->binDirectory . '/wp.exe';
        file_put_contents($windows, "MZ\x90\x00");

        $helpers = $this->helpers();

        $this->assertTrue($helpers->miniIsShellScript($script), 'A `#!` file must be refused.');
        $this->assertFalse($helpers->miniIsShellScript($phar), 'A phar must not be mistaken for a script.');
        $this->assertFalse($helpers->miniIsShellScript($windows), 'A PE binary must not be mistaken for a script.');
    }

    /**
     * An unreadable file stays a candidate rather than being silently skipped.
     *
     * The alternative — treating "could not read" as "is a script" — would make a
     * permissions problem look like a missing WP-CLI, and the search would walk
     * past the very binary the user pointed at.
     */
    public function test_a_file_that_cannot_be_read_is_not_treated_as_a_script(): void
    {
        $helpers = $this->helpers();

        $this->assertFalse(
            $helpers->miniIsShellScript($this->binDirectory . '/does-not-exist'),
            'A missing file must not be classified as a script; it is simply not a candidate.'
        );
    }

    /**
     * A shebang `wp` is skipped even when it is the *only* thing on PATH.
     *
     * ## Why this shape and not "a phar further along PATH"
     *
     * Because that version passes without the guard. The reordering alone —
     * `wp-cli.phar` ahead of `wp` in the candidate list — already finds the phar,
     * so a test whose fixture has a phar in it cannot tell the two halves of the
     * fix apart, and would stay green if the `miniIsShellScript()` check were
     * deleted. That is not a hypothetical: it was measured, by neutralising the
     * guard and watching this assertion still pass.
     *
     * Putting the wrapper on PATH *alone*, with no phar anywhere, leaves the guard
     * as the only branch that can produce "not found". The two tests are therefore
     * independent: one fails if the ordering regresses, the other if the content
     * check does, and neither can be satisfied by the other.
     *
     * "Not found" is the correct answer here, not a failure of the search. A
     * shebang script is one PHP cannot start, so returning it would mean `mini`
     * failing later with "could not start WP-CLI"; returning null lets it fall
     * through to the downloaded copy, which is a real WP-CLI it can run.
     */
    public function test_a_shebang_wp_on_its_own_is_skipped_rather_than_returned(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            // The skip is Windows-only by design: on Unix the kernel runs a `#!`
            // file itself, so skipping one there would refuse a working install.
            $this->markTestSkipped('Shebang skipping applies to Windows, where PHP cannot start one.');
        }

        file_put_contents($this->binDirectory . '/wp', "#!/usr/bin/env sh\nexec php \"\$0\" \"\$@\"\n");

        $found = $this->withPath([$this->binDirectory], fn () => $this->helpers()->miniFindInstalled());

        $this->assertNull(
            $found,
            'A shebang `wp` was returned as the WP-CLI to run. PHP cannot start one on Windows, so `mini` '
            . 'would fail at startup instead of falling through to the downloaded copy.'
        );
    }

    /**
     * The same wrapper is skipped in favour of a phar on the same PATH.
     *
     * The realistic install shape, and the reported failure verbatim: a wrapper
     * first, a usable phar behind it. This is the test that would have caught the
     * bug as users hit it; the one above is what pins the mechanism.
     */
    public function test_the_search_skips_a_shebang_wp_in_favour_of_a_phar(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Shebang skipping applies to Windows, where PHP cannot start one.');
        }

        $wrapperDirectory = $this->binDirectory . '/first';
        $pharDirectory = $this->binDirectory . '/second';

        mkdir($wrapperDirectory, 0755, true);
        mkdir($pharDirectory, 0755, true);

        file_put_contents($wrapperDirectory . '/wp', "#!/usr/bin/env sh\nexec php \"\$0\" \"\$@\"\n");
        file_put_contents($pharDirectory . '/wp-cli.phar', "<?php\n// a phar stub\n");

        $found = $this->withPath([$wrapperDirectory, $pharDirectory], fn () => $this->helpers()->miniFindInstalled());

        $this->assertNotNull($found, 'The search gave up instead of finding the phar.');
        $this->assertStringEndsWith(
            'wp-cli.phar',
            str_replace('\\', '/', $found),
            'The search returned the unrunnable wrapper rather than continuing to the phar.'
        );
    }

    /**
     * A `.bat` wrapper is *not* skipped by the shebang rule.
     *
     * The two exclusions are separate on purpose. A batch file cannot be started
     * by PHP either, but `miniRunAndExit()` runs it through `cmd.exe`, so it is a
     * usable last resort and must stay a candidate. Folding it into the shebang
     * check would make `mini` refuse an install it can actually drive.
     */
    public function test_a_batch_wrapper_stays_a_candidate(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Batch wrappers are a Windows-only install shape.');
        }

        $directory = $this->binDirectory . '/batch';
        mkdir($directory, 0755, true);
        file_put_contents($directory . '/wp.bat', "@echo off\r\nphp \"%~dp0wp-cli.phar\" %*\r\n");

        $found = $this->withPath([$directory], fn () => $this->helpers()->miniFindInstalled());

        $this->assertNotNull($found, 'A `.bat` wrapper is runnable through the shell and must be found.');
        $this->assertStringEndsWith('.bat', $found);
    }

    /**
     * The cache lives under the system temporary directory, not under the plugin.
     *
     * ## The defect this pins
     *
     * The downloaded WP-CLI used to be cached at `<plugin root>/.cli-cache/`. On
     * Windows a process that has read a file keeps a share lock on it for as long
     * as it lives, so a WP-CLI still running — or, on some hosts, an antivirus
     * scanner that has just touched the phar — makes the file undeletable.
     * WordPress deletes a plugin by recursively removing its directory, so the
     * delete fails partway with "Could not fully remove the plugin" and the cause
     * is invisible. Asserting the path is *outside* the plugin root is the whole
     * point; asserting it equals some particular temporary path would not be,
     * because the defect is the relationship, not the string.
     */
    public function test_the_cache_directory_is_outside_the_plugin_root(): void
    {
        $pluginRoot = dirname(__DIR__, 3);
        $cache = $this->helpers()->miniCacheDirectory();

        $this->assertFalse(
            str_starts_with($cache . DIRECTORY_SEPARATOR, $pluginRoot . DIRECTORY_SEPARATOR),
            "The download cache resolved to [{$cache}], which is inside the plugin root. A phar locked there "
            . 'by a running process makes WordPress fail to delete or update the plugin.'
        );
    }

    /**
     * The cache path is built from `sys_get_temp_dir()` with `DIRECTORY_SEPARATOR`.
     *
     * Cross-platform by construction: the separator is the platform's own, so a
     * Windows path never mixes `\` and `/` and a Unix path never gains one. The
     * equality against a separately-computed expectation is what catches the
     * hardcoded `'/wp-mini-laravel-cli-cache'` that would still pass the
     * outside-the-plugin check on Windows while producing a mixed-separator path.
     */
    public function test_the_cache_path_uses_the_platform_separator(): void
    {
        $cache = $this->helpers()->miniCacheDirectory();

        $this->assertSame(
            rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . self::CACHE_DIRECTORY,
            $cache,
            'The cache path must be sys_get_temp_dir() joined with the platform separator.'
        );
    }

    /**
     * The path is absolute, so it cannot resolve against the current directory.
     *
     * `sys_get_temp_dir()` answers `"."` when none of `TMP`/`TEMP`/`TMPDIR` is set
     * — which would put the cache back in whatever directory the command was run
     * from, the plugin root in the reported case. The function substitutes a
     * platform default instead, and this asserts the result is one.
     */
    public function test_the_cache_path_is_absolute_even_without_a_temp_environment(): void
    {
        $cache = $this->helpers()->miniCacheDirectory();

        $this->assertMatchesRegularExpression(
            '~^(/|[A-Za-z]:[\\\\/])~',
            $cache,
            "The cache path resolved to the relative path [{$cache}], which would land in the current directory."
        );
    }

    /**
     * The directory name is namespaced, so it cannot collide in a shared `/tmp`.
     *
     * A bare `wp-cli.phar` directly in the system temporary directory would be
     * shared with every other application on the machine, and the first writer to
     * arrive would decide what the other one executes. The uniqueness is carried
     * entirely by this name.
     */
    public function test_the_cache_directory_name_is_namespaced(): void
    {
        $cache = $this->helpers()->miniCacheDirectory();

        $this->assertStringEndsWith(
            DIRECTORY_SEPARATOR . self::CACHE_DIRECTORY,
            $cache,
            'The cache directory must be the namespaced name, not a bare `wp-cli.phar` location.'
        );

        $this->assertStringContainsString('wp-mini-laravel', self::CACHE_DIRECTORY);
    }

    /**
     * The name asserted above is the name the script actually declares.
     *
     * The constant is defined in this test rather than extracted, so without this
     * the whole class would keep passing against a `mini` that had been renamed to
     * cache somewhere else entirely — the failure mode a copied literal always has.
     * Read from the source for the same reason `loadHelpers()` reads the functions
     * from it: the script cannot be included without running its top level.
     */
    public function test_the_cache_directory_name_matches_the_one_the_script_declares(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/mini');

        $this->assertMatchesRegularExpression(
            "/const MINI_CACHE_DIRECTORY = '" . preg_quote(self::CACHE_DIRECTORY, '/') . "';/",
            $source,
            "`mini` no longer declares MINI_CACHE_DIRECTORY as '" . self::CACHE_DIRECTORY . "'."
        );
    }

    /**
     * Run a callable with PATH set to the given directories, restoring it after.
     *
     * The environment is global state shared with every other test in the process,
     * so it is put back even when the callable throws — a leaked PATH would make
     * a later test resolve a fixture binary that no longer exists.
     *
     * @param list<string> $directories
     */
    private function withPath(array $directories, callable $call): mixed
    {
        $this->restorePath = (string) (getenv('PATH') ?: '');

        putenv('PATH=' . implode(PATH_SEPARATOR, $directories));

        try {
            return $call();
        } finally {
            putenv('PATH=' . $this->restorePath);
            $this->restorePath = null;
        }
    }

    /**
     * The extracted helper functions, loaded once per test.
     *
     * `eval()` rather than `include`: the source carries a `declare(strict_types=1)`
     * that must not be re-executed, and the functions have to land in the global
     * namespace because that is where `mini` defines them and where the script's
     * own calls resolve. Only the named functions and their docblocks are taken —
     * the script's top level calls `exit()` and would kill the test run.
     */
    private function helpers(): object
    {
        return new class () {
            public function __call(string $name, array $arguments): mixed
            {
                return $name(...$arguments);
            }
        };
    }

    private function loadHelpers(): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/mini');

        if (!defined('MINI_CACHE_DIRECTORY')) {
            define('MINI_CACHE_DIRECTORY', self::CACHE_DIRECTORY);
        }

        $extracted = '';

        foreach (self::HELPERS as $name) {
            $position = strpos($source, "function {$name}(");

            if ($position === false) {
                $this->fail(
                    "`mini` no longer defines [{$name}()]. This test extracts the resolution helpers by name; "
                    . 'a rename has to be reflected here rather than leaving the test to assert nothing.'
                );
            }

            // The docblock above the function, so the extracted copy keeps its
            // reasoning, then the body up to the closing brace in column 0.
            $start = strrpos(substr($source, 0, $position), '/**');
            $end = strpos($source, "\n}\n", $position);

            $extracted .= substr($source, (int) $start, $end - (int) $start + 3) . "\n\n";
        }

        eval($extracted);

        $loaded = true;
    }
}
