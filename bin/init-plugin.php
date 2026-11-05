<?php
declare(strict_types=1);

/**
 * Rebrands this starter plugin for a new project.
 *
 * Run immediately after cloning, before writing any application code:
 *
 *     php bin/init-plugin.php \
 *         --name="Lead Bridge" \
 *         --slug=lead-bridge \
 *         --vendor=acme \
 *         --namespace=LeadBridge
 *
 * It performs the ten steps the README documents by hand, plus the two the
 * README omits. Add `--dry-run` to see every change without writing anything.
 *
 * ## What it deliberately does not do
 *
 * It does not touch `framework/`. That directory is a separate Composer package
 * whose `Framework\` namespace belongs to the library; renaming it here forks the
 * library and turns every future framework update into a merge. The README's own
 * opening table says "leave the library's alone", and steps 7 and 8 of its
 * checklist contradict that by asking you to hand-edit files under `framework/`.
 *
 * The strings those steps name — `wp_mini_laravel_` error codes, the
 * `wp-mini-laravel` text domain in `framework/`'s `__()` calls — are **fallback
 * defaults**, reached only when config is missing a key. `config('app.prefix',
 * 'wp_mini_laravel_')` returns *your* prefix on every real install, because your
 * config sets it. The library falls back to its own name because it cannot know
 * yours, and that is correct library behaviour. See `reportFrameworkDefaults()`
 * for the list this script prints rather than rewrites.
 *
 * ## Why it is a PHP script and not a shell one
 *
 * Because the replacements are identity-dependent, not textual. `App\` must
 * become `LeadBridge\` in a namespace declaration and in a `use` statement and
 * in a PSR-4 key, but `App` must **not** be replaced inside the word
 * `Application`, and a slug like `notes-app` must not have its `app` touched.
 * Doing that correctly needs word boundaries and a file-type-aware pass, which is
 * painful in `sed` and unpleasant in PowerShell — and this repository is
 * deliberately cross-platform, so a `.sh` script would exclude half its users.
 *
 * @package WP Mini Laravel
 */

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

// Not Composer's autoloader: this script runs before `composer install` in the
// README's own order (step 1 moves files, step 10 is the one that rebuilds the
// autoloader). Requiring vendor/autoload.php here would make the script fail on
// exactly the fresh clone it exists to serve.
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "init-plugin.php must be run from the command line.\n");
    exit(1);
}

// The repository root, one level above bin/. Resolved from __DIR__ rather than
// from getcwd(), so the script works when invoked by an absolute path from
// anywhere — `php /path/to/bin/init-plugin.php` is a normal way to run this.
define('INIT_PLUGIN_ROOT', dirname(__DIR__));

require __DIR__ . '/lib/RebrandPlan.php';
require __DIR__ . '/lib/Rebrander.php';

use WpMiniLaravel\Init\Rebrander;
use WpMiniLaravel\Init\RebrandPlan;

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

$options = parseArguments($argv);

if ($options === null) {
    exit(1);
}

$plan = RebrandPlan::fromOptions($options);

// The plan validates before the rebrander runs. Every rule it enforces is one
// whose violation fails *quietly* — a slug with a capital letter produces a
// plugin folder WordPress cannot address, a namespace `App` collides with the
// default it is meant to replace — so a report here is the only warning anyone
// gets. See RebrandPlan::validate().
$problems = $plan->validate();

if ($problems !== []) {
    fwrite(STDERR, "\nCannot rebrand: the values given are not usable.\n\n");

    foreach ($problems as $problem) {
        fwrite(STDERR, "  - {$problem}\n");
    }

    fwrite(STDERR, "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

$rebrander = new Rebrander(INIT_PLUGIN_ROOT, $plan, (bool) $options['dry-run']);

$rebrander->run();

exit(0);

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------

/**
 * Read the command line into an options array, or report usage and return null.
 *
 * @param list<string> $argv
 *
 * @return array<string, string|bool>|null
 */
function parseArguments(array $argv): ?array
{
    $defaults = [
        // Identity
        'name' => '',
        'slug' => '',
        'vendor' => '',
        'namespace' => '',

        // Derived-valued overrides. Left empty, they are computed from the
        // identity above — which is the point: a user who supplies only the four
        // required values should not have to invent a REST namespace, and the
        // derived one is guaranteed to match the README's conventions.
        'constant-prefix' => '',
        'option-prefix' => '',
        'cli-prefix' => '',
        'rest-namespace' => '',
        'text-domain' => '',
        'core-namespace' => '',

        // Behaviour
        'dry-run' => false,
        'help' => false,
    ];

    $options = $defaults;

    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;

            continue;
        }

        if ($argument === '--dry-run') {
            $options['dry-run'] = true;

            continue;
        }

        if (!str_starts_with($argument, '--')) {
            fwrite(STDERR, "Unexpected argument: {$argument}\n");
            printUsage();

            return null;
        }

        // `--key=value` only. A bare `--key value` spelling would make an
        // omitted value swallow the *next* flag — `--name --slug=foo` would set
        // the name to the literal string "--slug=foo" — and silently rebrand
        // the plugin to a nonsense name.
        if (!str_contains($argument, '=')) {
            fwrite(STDERR, "Expected --key=value, got: {$argument}\n");
            printUsage();

            return null;
        }

        [$key, $value] = explode('=', substr($argument, 2), 2);

        if (!array_key_exists($key, $defaults)) {
            fwrite(STDERR, "Unknown option: --{$key}\n");
            printUsage();

            return null;
        }

        $options[$key] = $value;
    }

    if ($options['help']) {
        printUsage();

        return null;
    }

    // The four required values. Reported together rather than one at a time, so
    // a user running this with no arguments sees the whole contract at once
    // instead of fixing one flag per attempt.
    $missing = [];

    foreach (['name', 'slug', 'vendor', 'namespace'] as $required) {
        if (trim((string) $options[$required]) === '') {
            $missing[] = '--' . $required;
        }
    }

    if ($missing !== []) {
        fwrite(STDERR, "\nMissing required option(s): " . implode(', ', $missing) . "\n");
        $options['help'] = true;
        printUsage();

        return null;
    }

    /** @var array<string, string|bool> $options */
    return $options;
}

function printUsage(): void
{
    $usage = <<<'TXT'

    Rebrand the WP Mini Laravel starter for a new project.

    USAGE
      php bin/init-plugin.php --name=... --slug=... --vendor=... --namespace=... [options]

    REQUIRED
      --name="Lead Bridge"     Plugin name. Appears in the plugin header and admin notices.
      --slug=lead-bridge       Plugin folder and entry-file name. Lowercase, hyphens only.
      --vendor=acme            Composer vendor. Replaces `devlinkstudios` in both composer.json files.
      --namespace=LeadBridge   App namespace. Replaces `App\` in your code.

    OPTIONAL (derived from the values above when omitted)
      --constant-prefix=LEAD_BRIDGE_    PHP constant prefix. Default: SNAKE(namespace)_
      --option-prefix=lead_bridge_      Option/transient/nonce prefix. Default: SNAKE(slug)_
      --cli-prefix=leadbridge           WP-CLI namespace. Default: LOWER(namespace)
      --rest-namespace=lead-bridge/v1   REST namespace. Default: slug/v1
      --text-domain=lead-bridge         i18n text domain. Default: slug
      --core-namespace=LeadBridge\Core  Core namespace. Default: namespace\Core

    BEHAVIOUR
      --dry-run    Print every change without writing anything.
      --help       Show this message.

    The framework/ directory is never modified — see the script's own docblock.

    TXT;

    fwrite(STDOUT, $usage . "\n");
}
