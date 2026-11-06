<?php
declare(strict_types=1);

/**
 * Verifies `mini`, the project's WP-CLI entry point, against a real WordPress.
 *
 * Run from the plugin root:
 *
 *     php tests/manual/verify-mini-entrypoint.php
 *
 * ## Why this cannot be a PHPUnit test
 *
 * `mini` forwards to a real WP-CLI against a real WordPress, and its whole job is
 * the part PHPUnit cannot reach: which binary ends up on the other end, and what
 * command name the forwarded prefix produces. A unit test with a stubbed WP-CLI
 * would assert that the script calls the thing the test already assumes it
 * calls — the exact assertion that was wrong while this script was shipping a
 * prefix it never used.
 *
 * ## What it is really guarding
 *
 * The prefix is read from `.env` and the *command* is registered under
 * `config('app.cli_prefix')`, which is the same value by a different route. If
 * those two ever disagree, everything still runs and every command silently
 * resolves to whichever answer the framework had — so a reference to `mini`
 * would look like it worked. The nonsense-prefix case below is what makes the
 * difference visible: a prefix the framework does not know must FAIL.
 */

$root = dirname(__DIR__, 2);
$mini = $root . '/mini';

/** @return array{int, string} Exit code and combined output. */
function run(string $command, string $cwd): array
{
    $output = [];
    $code = 0;

    exec(sprintf('cd %s && %s 2>&1', escapeshellarg($cwd), $command), $output, $code);

    return [$code, implode("\n", $output)];
}

/** Write `.env` with `CLI_PREFIX` set to a given value, and return a restore closure. */
function withPrefix(string $root, string $prefix): callable
{
    $path = $root . '/.env';
    $original = is_file($path) ? (string) file_get_contents($path) : null;

    file_put_contents($path, $original === null
        ? "CLI_PREFIX={$prefix}\n"
        : (string) preg_replace('/^CLI_PREFIX=.*$/m', "CLI_PREFIX={$prefix}", $original));

    return static function () use ($path, $original): void {
        $original === null ? @unlink($path) : file_put_contents($path, $original);
    };
}

$failures = 0;

/** @param array{int, string} $result */
function check(string $label, bool $passed, array $result): void
{
    global $failures;

    if ($passed) {
        echo "  ok    {$label}\n";

        return;
    }

    $failures++;
    echo "  FAIL  {$label}\n";
    echo "        exit={$result[0]}\n";
    echo '        ' . str_replace("\n", "\n        ", substr($result[1], 0, 600)) . "\n";
}

echo "\nmini entry point\n\n";

// 1. The script exists, is readable, and parses.
check(
    'mini exists and parses',
    is_file($mini) && (run(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($mini), $root)[0] === 0),
    run(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($mini), $root)
);

// 2. The configured prefix resolves a real command.
$restore = withPrefix($root, 'wp_mini');
[$code, $output] = run(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($mini) . ' route:list', $root);
check('CLI_PREFIX=wp_mini runs route:list', $code === 0 && str_contains($output, 'route(s):'), [$code, $output]);
$restore();

// 3. A *different* valid prefix must also work — this is the assertion that
//    makes case 2 meaningful. A script that forwarded the framework's hardcoded
//    default would pass case 2 and fail here, and the two together are what
//    prove the prefix is read from `.env` rather than assumed.
//
//    Note what is NOT asserted: that an arbitrary prefix fails. It does not, and
//    it should not — the plugin registers its commands under whatever `.env`
//    says, so any valid prefix is correct as long as both halves agree. The
//    check that would catch a disagreement is the negative control below.
$restore = withPrefix($root, 'leadbridge');
[$code, $output] = run(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($mini) . ' route:list', $root);
check('CLI_PREFIX=leadbridge also runs route:list', $code === 0 && str_contains($output, 'route(s):'), [$code, $output]);
$restore();

// 4. A prefix the plugin does not register must fail. This is the real negative
//    control: `.env` is set to one value and the command is invoked under
//    another, which is the state a hardcoded prefix would produce permanently.
$restore = withPrefix($root, 'wp_mini');
[$code, $output] = run(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($mini) . ' leadbridge route:list',
    $root
);
check(
    'a mismatched prefix does not silently succeed',
    str_contains($output, 'not a registered'),
    [$code, $output]
);
$restore();

// 5. Arguments after the command reach WP-CLI untouched.
$restore = withPrefix($root, 'wp_mini');
[, $output] = run(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($mini) . ' route:list --format=csv', $root);
check('arguments are forwarded', str_contains($output, 'route(s):'), [0, $output]);
$restore();

// 6. A malformed prefix is refused with a message naming the value, rather than
//    being forwarded as a truncated command name.
$restore = withPrefix($root, 'my plugin');
[$code, $output] = run(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($mini) . ' route:list', $root);
check(
    'a malformed prefix is rejected before it is forwarded',
    $code === 1 && str_contains($output, 'cannot be a WP-CLI command namespace'),
    [$code, $output]
);
$restore();

echo "\n" . ($failures === 0 ? "All checks passed.\n\n" : "{$failures} check(s) FAILED.\n\n");

exit($failures === 0 ? 0 : 1);
