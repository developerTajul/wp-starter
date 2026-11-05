<?php
declare(strict_types=1);

namespace WpMiniLaravel\Init;

/**
 * Performs a {@see RebrandPlan} against a plugin directory.
 *
 * ## The three passes, and why they are ordered
 *
 *  1. **Contents** — every eligible file's text is rewritten.
 *  2. **Paths** — the entry file is renamed.
 *  3. **Report** — what was skipped, and what the user must run next.
 *
 * Contents before paths, because the path pass renames the entry file the
 * contents pass has just finished rewriting; doing it the other way would mean
 * resolving the new name back to the old one for every file that references it.
 * In practice nothing references the entry file by name except the test that
 * loads it, which the contents pass rewrites — see {@see replacementRules()}.
 *
 * ## The framework boundary
 *
 * `framework/` is excluded from every pass, and the reason is in the entry
 * script's docblock: it is a separate Composer package whose namespace belongs to
 * the library, not to this project. {@see reportFrameworkDefaults()} prints the
 * occurrences it *could* have rewritten so the boundary is visible rather than
 * mysterious — a user who greps for the old name after rebranding will find them,
 * and needs to know why they are still there.
 *
 * ## Why the file list is discovered, not hardcoded
 *
 * The README's checklist names five files for step 6, and step 6 is already stale:
 * it does not mention `app/Http/Controllers/`, `app/Http/Middleware/` or
 * `app/Http/Resources/`, all of which carry the `App\` namespace and all of which
 * exist in this repository. A hardcoded list is a list that drifts from the tree
 * the moment anyone adds a file. So the walk discovers what is there, and the
 * rules below decide what each kind of file gets.
 */
final class Rebrander
{
    /**
     * Directories never rewritten.
     *
     *  - **`framework`** is the library — see the class docblock. It is reported
     *    on but never modified.
     *  - **`bin`** is *this script*. Rewriting it would edit the replacement
     *    rules while they are running, and the damage is not visible until a
     *    second run: the rules would by then be looking for a slug the tree no
     *    longer contains, so the second run would silently change nothing and a
     *    user would conclude the rebrand was already done. A rebranding tool that
     *    breaks when run twice is worse than one that refuses.
     *  - **`vendor`** is rebuilt from `composer.json`. `.git` is repository
     *    history, which rewriting corrupts rather than rebrands.
     *
     * @var list<string>
     */
    private const SKIP_DIRECTORIES = [
        'framework',
        'bin',
        'vendor',
        '.git',
        'node_modules',
        '.phpunit.cache',
    ];

    /**
     * Extensions eligible for content rewriting.
     *
     * An allow-list rather than a deny-list, because this pass rewrites files in
     * a directory the user owns: a binary that happened to contain the byte
     * sequence `App\` — a `.lock` file's hash, an image, a `.phar` — would be
     * corrupted by a text replacement, and a deny-list cannot enumerate every
     * binary format a project might carry.
     *
     * @var list<string>
     */
    private const TEXT_EXTENSIONS = [
        'php', 'json', 'md', 'txt', 'stub', 'xml', 'dist', 'yml', 'yaml', 'css', 'js', 'pot', 'po',
    ];

    /**
     * Files never rewritten, even when their extension is eligible.
     *
     * `composer.lock` is the important one: it records the resolved dependency
     * graph, including the framework package's name and its content hash. Editing
     * it by hand makes Composer report a lock file out of date and then reinstall
     * — at best. At worst the hash no longer matches and the install is refused
     * with a message about tampering. The correct fix is the `composer update`
     * this script tells the user to run.
     *
     * `package-lock.json` is the same argument for npm.
     *
     * @var list<string>
     */
    private const SKIP_FILES = [
        'composer.lock',
        'package-lock.json',
        'yarn.lock',
    ];

    /**
     * The default identifiers to look for under `framework/`.
     *
     * Ordered longest-prefix-first so a file carrying several reports the most
     * specific: `wp_mini_laravel_flash` is reported as `wp_mini_laravel_` rather
     * than also as `wp_mini`, which is a prefix of it. Both are true; only one is
     * the useful thing to tell someone.
     *
     * @var list<string>
     */
    private const FRAMEWORK_DEFAULT_NEEDLES = [
        'WP Mini Laravel',
        'WP_MINI_LARAVEL_',
        'wp_mini_laravel_',
        'wp-mini-laravel',
        'wp_mini',
    ];

    /**
     * The framework package's Composer name.
     *
     * Fixed, and deliberately not derived from the plan. The framework is a
     * separate package that the rebrand never rewrites — `framework/` is
     * read-only — so its manifest keeps this name and Composer resolves the
     * dependency by it. A rebrand changes the project's namespaces and its own
     * package identity; it does not rename a dependency it does not own.
     *
     * Named once here because three passes have to agree on it: the string pass
     * must map it to itself, the dependency pass must not move it, and the
     * `name` pass must not recompute it.
     */
    private const FRAMEWORK_PACKAGE = 'devlinkstudios/wp-mini-laravel-framework';

    /**
     * Every file the contents pass changed, relative to the root.
     *
     * @var list<string>
     */
    private array $changed = [];

    /**
     * Occurrences found under `framework/`, reported but never rewritten.
     *
     * @var array<string, list<string>> File path => the identifiers found in it.
     */
    private array $frameworkDefaults = [];

    /**
     * The root package's own name, read before any file is rewritten.
     *
     * Held on the instance rather than read on demand because the value ceases to
     * exist the moment the manifest is rewritten — see {@see readRootPackageName()}.
     */
    private readonly ?string $rootPackageName;

    public function __construct(
        private readonly string $root,
        private readonly RebrandPlan $plan,
        private readonly bool $dryRun = false,
    ) {
        $this->rootPackageName = $this->readRootPackageName();
    }

    /**
     * Run the rebrand.
     */
    public function run(): void
    {
        $this->banner();

        $this->rewriteContents();

        // The hybrid passes: structural rebranding above, then the dynamic
        // configuration it feeds. `config/app.php`'s env() defaults are updated
        // after the content pass so the identity strings this method looks for
        // are the ones the project shipped with, not ones already rewritten.
        $this->rewriteConfigFallbacks();
        $this->rewriteDotenvFiles();

        $this->renameEntryFile();

        // Scanned here rather than in the main walk — see scanFrameworkDirectory()
        // for why the main walk structurally cannot do it.
        $this->scanFrameworkDirectory();
        $this->reportFrameworkDefaults();

        $this->reportNextSteps();

        if ($this->dryRun) {
            fwrite(STDOUT, "\n  DRY RUN — nothing was written.\n\n");
        }
    }

    // ------------------------------------------------------------ pass 1: contents

    /**
     * Rewrite the contents of every eligible file.
     */
    private function rewriteContents(): void
    {
        fwrite(STDOUT, "  Rewriting file contents\n");

        foreach ($this->eligibleFiles() as $path) {
            $relative = $this->relative($path);
            $original = (string) file_get_contents($path);

            // ## Why JSON gets its own pass
            //
            // A namespace inside a JSON string is spelled with a doubled
            // separator — `"Core\\"` — because that is JSON's escape for one
            // backslash. Textual replacement has to reproduce that doubling
            // through `preg_replace`'s own escaping rules, and getting it wrong
            // writes a lone `\C` into the file: an invalid escape, in a file the
            // script then reports as rebranded. That is not a hypothetical — it is
            // the bug this branch exists to remove, and it survived three attempts
            // to fix it textually.
            //
            // So JSON is not edited as text at all. It is parsed, the structure is
            // changed, and it is re-encoded — which means the encoder owns the
            // escaping and no backslash is ever counted by hand. See
            // {@see rewriteJsonFile()}.
            // `framework/` is skipped by the walk, so no file under it arrives
            // here — but this branch is what *keeps* that true for JSON, and
            // saying so explicitly means a future change to SKIP_DIRECTORIES
            // cannot quietly start rewriting the library's own manifest.
            if (str_ends_with($relative, '.json')) {
                $rewritten = $this->isUnderFramework($relative)
                    ? $original
                    : $this->rewriteJsonFile($relative, $original);
            } else {
                $rewritten = $original;

                foreach ($this->replacementRules($relative) as $index => [$pattern, $replacement]) {
                    // The replacement is used verbatim. Every backslash in it is
                    // written at its definition site in {@see replacementRules()},
                    // already counted for preg_replace's own collapsing — see the
                    // long note there. Nothing is escaped or post-processed here,
                    // because a second pass that "helpfully" doubled backslashes
                    // would break rules that were already correct.
                    $result = preg_replace($pattern, $replacement, $rewritten);

                    // ## Why a null result is fatal, and not a no-op
                    //
                    // `preg_replace()` returns null when the pattern will not
                    // compile — a lookbehind that is not fixed length, a missing
                    // delimiter, a bad escape. Casting that to a string yields the
                    // empty string, so the naive `(string) preg_replace(...)` does
                    // not skip the rule: it *blanks the file*.
                    //
                    // That is not a hypothetical. A variable-length lookbehind was
                    // added to one of the namespace rules, and this script
                    // proceeded to overwrite every eligible file in the tree with
                    // zero bytes while reporting a successful rebrand — having
                    // already renamed the entry file, so the project was left
                    // unbootable and the source of the damage was 200 lines away
                    // from the symptom.
                    //
                    // A pattern that does not compile is a bug in this script, and
                    // the only safe response is to stop before writing anything.
                    if ($result === null) {
                        fwrite(STDERR, sprintf(
                            "\n  FATAL: a replacement pattern failed to compile while rewriting %s.\n"
                            . "  Rule index %d: %s\n"
                            . "  PCRE said: %s\n\n"
                            . "  Nothing has been written for this file, and the script has stopped.\n"
                            . "  This is a bug in framework/bin/lib/Rebrander.php, not in your project — the\n"
                            . "  pattern is malformed, and continuing would overwrite files with\n"
                            . "  empty content.\n\n",
                            $relative,
                            $index,
                            $pattern,
                            preg_last_error_msg()
                        ));

                        exit(1);
                    }

                    $rewritten = $result;
                }
            }

            if ($rewritten === $original) {
                continue;
            }

            $this->changed[] = $relative;

            // The line count is worth reporting because a rewrite that touches
            // far more than expected is the visible symptom of a rule whose
            // word boundaries are wrong — `App` matching inside `Application`,
            // or a slug matching inside a longer identifier. Printing it lets
            // that be seen here rather than in a failing test suite later.
            fwrite(STDOUT, sprintf(
                "    %-58s %d change%s\n",
                $relative,
                substr_count($rewritten, "\n") >= 0 ? $this->countDiffLines($original, $rewritten) : 0,
                $this->countDiffLines($original, $rewritten) === 1 ? '' : 's'
            ));

            if (!$this->dryRun) {
                $this->writeFile($path, $rewritten);
            }
        }

        if ($this->changed === []) {
            fwrite(STDOUT, "    (no files needed changes)\n");
        }
    }

    // ------------------------------------------------------- pass 1a: JSON files

    /**
     * Rewrite a JSON file structurally rather than textually.
     *
     * ## What this replaces, and why
     *
     * The first three attempts at this script rewrote `composer.json` with
     * `preg_replace`, and all three got the escaping wrong in a different way.
     * The reason is structural rather than a mistake in any one pattern: a
     * namespace separator has to survive **three** transformations to reach a
     * JSON string —
     *
     *   1. the pattern and replacement literals in this file's own PHP source;
     *   2. `preg_replace`'s replacement parser, which collapses `\\` to `\`;
     *   3. JSON's own escaping, which spells a literal backslash `\\`.
     *
     * — and a rule that satisfies all three has to encode layer 3 by hand inside
     * a string that layer 2 is already rewriting. The failure is always the same
     * shape and always silent: a separator left single, so `"Core\\"` becomes
     * `"LeadBridge\Core\"`, whose lone `\C` is an invalid JSON escape. Composer
     * then refuses a file this script has already reported as rebranded.
     *
     * ## Why decode-and-encode removes the whole class of bug
     *
     * `json_decode()` turns the file into PHP values, so a namespace arrives as
     * the *logical* string `Core\` — one backslash, no escaping, because the
     * decoder already undid layer 3. `json_encode()` re-applies layer 3 on the
     * way out. Neither this code nor its author counts a backslash at any point;
     * the encoder owns the escaping, and it is the same encoder Composer itself
     * uses.
     *
     * ## Why the keys are rebuilt rather than string-replaced
     *
     * A PSR-4 key is a namespace *prefix*, and the mapping is from prefix to
     * directory — so `"Core\\"` is the key `Core\` and its value is `"core/"`.
     * Replacing the key wholesale is what the mapping actually means; running a
     * text substitution over the decoded value would reintroduce exactly the
     * ambiguity being removed, since the decoded string no longer contains the
     * doubled separator the text rules look for.
     *
     * ## Why `JSON_UNESCAPED_SLASHES`
     *
     * Composer writes `"core/"`, not `"core\/"`. Both are valid JSON and only one
     * matches the file this project committed — which matters because the script
     * is run on a working tree, and a diff full of `\/` would bury the changes
     * that actually matter.
     *
     * @return string The re-encoded file, or the original when it cannot be
     *                parsed or has nothing to change.
     */
    private function rewriteJsonFile(string $relative, string $original): string
    {
        $data = json_decode($original, true);

        // A file that will not parse is left completely alone, and that is
        // deliberate. Writing `json_encode(null)` over a malformed
        // `composer.json` would destroy it — turning a file with a trailing comma
        // into an empty one — and the user would have no way to recover the
        // original from a script that also rewrote everything else. A wrong file
        // that is still readable beats a correct file that is gone.
        if (!is_array($data)) {
            fwrite(STDOUT, sprintf(
                "    %-58s SKIPPED — not valid JSON, left untouched\n",
                $relative
            ));

            return $original;
        }

        // All three run unconditionally, with `|` rather than `||`. Each returns
        // 1/0 and each mutates a different part of the document, so short-
        // circuiting on the first would silently skip the rest — a `composer.json`
        // whose `name` was already correct would keep the old namespace mapping.
        $changed = $this->rewriteJsonPackageName($data)
            | $this->rewriteJsonPsr4($data)
            | $this->rewriteJsonStrings($data)
            | $this->rewriteJsonDependencies($data);

        if ($changed === 0) {
            return $original;
        }

        $encoded = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($encoded === false) {
            return $original;
        }

        // json_encode() omits the trailing newline that every hand-written and
        // Composer-generated JSON file ends with. Matching it keeps the rebrand
        // from showing up as a "no newline at end of file" diff on every JSON
        // file in the project.
        return $encoded . "\n";
    }

    // --------------------------------------------------- pass 1a-ii: JSON dependencies

    /**
     * Resolve every Composer requirement the rebrand can actually resolve.
     *
     * ## Why a mapping pass exists at all
     *
     * {@see rewriteJsonStrings()} is blunt on purpose: it rewrites the four
     * identity strings anywhere they appear, including inside a `require` block.
     * That is right for the project's own package — `acme/lead-bridge` — but
     * wrong for the framework, which it rewrites to
     * `<vendor>/wp-mini-laravel-framework`.
     *
     * That string is not the project's to choose. The framework is a separate
     * package with a `name` of its own, and Composer resolves a requirement by
     * that name against a real repository. Renaming the requirement locally does
     * not rename the package; it only guarantees the install fails with
     * "could not be found in any version". So every requirement is checked
     * against what is actually known, and anything unrecognised is left exactly
     * as written.
     *
     * ## Why the two classes of key are treated differently
     *
     * `require` and `require-dev` name packages. `repositories[].url` does not —
     * it is a *location*, and `./framework` happens to be wherever this checkout
     * put it. A `url` is never rewritten: the plan has no idea where the user
     * keeps their code, and inventing a path is worse than leaving a correct one
     * alone.
     *
     * ## Why the framework package is renamed to the vendor verbatim
     *
     * `devlinkstudios/wp-mini-laravel-framework` becomes
     * `<vendor>/wp-mini-laravel-framework` — the new vendor, the *library's* slug,
     * untouched. This is the same string {@see rewriteJsonStrings()} produces, and
     * that is the point: it is not a decision this pass makes, it is the one
     * answer that is not a guess. What the pass actually decides is which package
     * names get to *keep* it.
     *
     * @param array<string, mixed> $data
     *
     * @return int 1 when something changed, 0 otherwise.
     */
    private function rewriteJsonDependencies(array &$data): int
    {
        $changed = 0;
        $moved = [];
        $kept = [];

        foreach (['require', 'require-dev'] as $section) {
            if (!isset($data[$section]) || !is_array($data[$section])) {
                continue;
            }

            $requirements = [];
            $rewrittenItt = false;

            foreach ($data[$section] as $package => $constraint) {
                $package = (string) $package;

                // Everything that is not the framework is left alone — including
                // the project's own name, which rewriteJsonStrings() has already
                // set correctly, and `php` / `phpunit/phpunit`, which no rebrand
                // should ever touch.
                if (!str_ends_with($package, '-framework')) {
                    $requirements[$package] = $constraint;

                    continue;
                }

                $target = $this->resolvePackage($package);

                if ($target === null) {
                    $requirements[$package] = $constraint;

                    continue;
                }

                if ($target === $package) {
                    // Kept, and said out loud. A requirement that survives the
                    // rebrand unchanged is indistinguishable in the written file
                    // from one the user wrote by hand, and "did the script see my
                    // framework dependency?" is exactly the question someone
                    // reading this output is asking.
                    $kept[] = $package;

                    $requirements[$package] = $constraint;

                    continue;
                }

                $moved[] = $package . ' -> ' . $target;
                $requirements[$target] = $constraint;
                $rewrittenItt = true;
            }

            if ($rewrittenItt) {
                $data[$section] = $requirements;
                $changed++;
            }
        }

        foreach ($moved as $move) {
            fwrite(STDOUT, sprintf("    %-58s package %s\n", 'composer.json', $move));
        }

        foreach ($kept as $package) {
            fwrite(STDOUT, sprintf(
                "    %-58s package %s kept (framework is published under this name)\n",
                'composer.json',
                $package
            ));
        }

        return $changed > 0 ? 1 : 0;
    }

    /**
     * The name a requirement should carry, or null to leave it as written.
     *
     * ## Why this is a lookup and not a rule
     *
     * There are exactly two names a `-framework` requirement can mean here, and
     * both are known before the first file is touched: the repository the user
     * cloned — read from the root `composer.json` — and the library's published
     * name, which is fixed at `devlinkstudios/wp-mini-laravel-framework` forever
     * because it is the name on Packagist.
     *
     * A conventional rule ("rename any `-framework` requirement to the new
     * vendor") would have been shorter and would have been wrong for the common
     * case: a project that requires the *published* library, which is precisely
     * the project that is not a clone of this repository. Renaming that
     * requirement breaks an install that was working. A lookup cannot make that
     * mistake, because the published name is a value in the table rather than a
     * pattern that happens to match it.
     */
    private function resolvePackage(string $package): ?string
    {
        // The library on Packagist. Its vendor is fixed and is not derived from
        // anything the user passed — that is what "published" means.
        //
        // ## Why this returns the name unchanged
        //
        // A package name is the *package's* to choose, and the framework keeps
        // its own: `framework/` is read-only, so `framework/composer.json` still
        // says `devlinkstudios/wp-mini-laravel-framework` after a rebrand. A
        // `path` repository does not attenuate that — it reads the target
        // package's own manifest for its identity, so the package Composer
        // installs is called that no matter what namespace this script is
        // rewriting into.
        //
        // Renaming the *requirement* while the *package* keeps its name does not
        // rename anything. It asks Composer for something no repository offers,
        // and the install ends at "could not be found in any version" with a
        // root manifest that no longer matches the tree beside it. Measured, not
        // assumed: the rename was tried on a rebranded copy and that is the exact
        // failure it produced.
        //
        // So the requirement is returned as written. This is not the "leave it
        // alone" branch below — the return is deliberate and the value is known,
        // which is why the move is not silent: see the report in
        // {@see rewriteJsonDependencies()}.
        if ($package === self::FRAMEWORK_PACKAGE) {
            return $package;
        }

        // The repository this script is running inside. Read from the root
        // `composer.json` rather than the plan, so that the requirement follows
        // the package the user actually cloned instead of whatever the plan last
        // wrote — the plan is the destination, the manifest is the source.
        if ($package === $this->rootPackageName) {
            return $package;
        }

        return null;
    }

    /**
     * The `name` field of the root `composer.json`, read before anything runs.
     *
     * ## Why this is read at construction
     *
     * By the time {@see resolvePackage()} is called, the root manifest has
     * already been rewritten in memory and, on a non-dry run, on disk — the
     * package the user cloned no longer exists under its old name anywhere in
     * the tree. Reading it once, up front, is the only point at which the
     * original name is still available.
     *
     * A manifest that is missing or unparseable yields null, and the lookup then
     * simply finds no local match: the requirement is left as written, which is
     * the conservative outcome.
     */
    private function readRootPackageName(): ?string
    {
        $path = $this->root . '/composer.json';

        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!is_array($data) || !isset($data['name']) || !is_string($data['name'])) {
            return null;
        }

        return $data['name'] === '' ? null : $data['name'];
    }

    /**
     * Rename the Composer package in `name`.
     *
     * ## Why this is not handled by the string pass
     *
     * `"devlinkstudios/wp-mini-laravel"` contains the old vendor *and* the old
     * slug, and the framework package is `"devlinkstudios/wp-mini-laravel-framework"`
     * — same vendor, slug plus a suffix. A single textual rule for either would
     * have to know which of the two it was looking at. Read as structure, the
     * distinction is just "is this the framework package or not", which is a
     * question about a string suffix rather than a pattern.
     *
     * @param array<string, mixed> $data
     *
     * @return int 1 when something changed, 0 otherwise.
     */
    private function rewriteJsonPackageName(array &$data): int
    {
        $current = $data['name'] ?? null;

        if (!is_string($current) || $current === '') {
            return 0;
        }

        // The framework package keeps its `-framework` suffix and takes the new
        // vendor; the project package takes the new vendor and the new slug.
        //
        // The framework branch is a no-op that states a rule rather than a change:
        // `framework/` is read-only and its manifest is never rewritten, so the
        // package's name is fixed at `devlinkstudios/wp-mini-laravel-framework`.
        // Recomputing a different name here would only put this copy at odds with
        // the one a `path` repository actually resolves against.
        $isFrameworkPackage = str_ends_with($current, '-framework');

        $new = $isFrameworkPackage
            ? self::FRAMEWORK_PACKAGE
            : $this->plan->vendor . '/' . $this->plan->slug;

        if ($new === $current) {
            return 0;
        }

        $data['name'] = $new;

        return 1;
    }

    /**
     * Repoint the `autoload.psr-4` and `autoload-dev.psr-4` maps.
     *
     * ## Why both maps
     *
     * `autoload.psr-4` maps the project namespaces; `autoload-dev.psr-4` maps
     * `Tests\`. A rebrand that changed one and not the other leaves the suite
     * unable to resolve its own classes — and since the README's own verification
     * step is `composer test`, that failure surfaces immediately and looks like a
     * broken namespace in `app/` rather than an untouched key in `composer.json`.
     *
     * The directory values are left alone. They are paths, not identities, and a
     * project is entitled to keep `app/` where it is.
     *
     * @param array<string, mixed> $data
     *
     * @return int 1 when something changed, 0 otherwise.
     */
    private function rewriteJsonPsr4(array &$data): int
    {
        $changed = 0;

        foreach (['autoload', 'autoload-dev'] as $section) {
            if (!isset($data[$section]['psr-4']) || !is_array($data[$section]['psr-4'])) {
                continue;
            }

            $rewritten = [];

            foreach ($data[$section]['psr-4'] as $prefix => $path) {
                // The decoded key is a logical namespace, e.g. `Core\` with one
                // backslash — the decoder already stripped JSON's doubling.
                $prefix = (string) $prefix;

                $newPrefix = match (true) {
                    // `App\` -> `LeadBridge\`
                    $prefix === 'App\\' => $this->plan->namespace . '\\',

                    // `Core\` -> `LeadBridge\Core\`
                    $prefix === 'Core\\' => $this->plan->coreNamespace . '\\',

                    // Anything already carrying the target namespace is left as
                    // it is, which is what makes a second run a no-op rather than
                    // a double-prefix.
                    str_starts_with($prefix, $this->plan->namespace . '\\'),
                    $prefix === $this->plan->namespace . '\\' => $prefix,

                    default => $prefix,
                };

                if ($newPrefix !== $prefix) {
                    $changed++;
                }

                $rewritten[$newPrefix] = $path;
            }

            $data[$section]['psr-4'] = $rewritten;
        }

        return $changed > 0 ? 1 : 0;
    }

    /**
     * Rewrite the identity strings that live in a JSON file's values.
     *
     * Only the four that appear in this repository's JSON: the framework
     * requirement's vendor, the `description`, and the two `keywords`-style
     * strings a project may carry. Applied to string *values* at any depth, since
     * a value has no structural meaning to preserve — unlike a key, where the
     * namespace prefix is the identity.
     *
     * @param array<string, mixed> $data
     *
     * @return int 1 when something changed, 0 otherwise.
     */
    private function rewriteJsonStrings(array &$data): int
    {
        $changed = 0;

        array_walk_recursive($data, function (&$value) use (&$changed): void {
            if (!is_string($value)) {
                return;
            }

            // Needles are ordered longest-first, and that ordering is load
            // bearing. `minusculas` on the array applies them in sequence, so
            // `devlinkstudios/wp-mini-laravel-framework` must meet the framework
            // needle before the `wp-mini-laravel` needle — otherwise the slug
            // rule rewrites the suffix first and the framework needle can no
            // longer match. The vendor needle is deliberately last for the same
            // reason: it is the shortest-lived pattern of the four.
            $rewritten = str_replace(
                [
                    'devlinkstudios/wp-mini-laravel-framework',
                    'WP Mini Laravel',
                    'wp_mini_laravel',
                    'wp-mini-laravel',
                    'devlinkstudios',
                ],
                [
                    // To itself, because the framework is published under this
                    // name and keeps it — see resolvePackage(). This entry is what
                    // stops the `wp-mini-laravel` rule below from rewriting the
                    // requirement's suffix out from under it, and the vendor rule
                    // last from splitting the vendor off the front.
                    self::FRAMEWORK_PACKAGE,
                    $this->plan->name,
                    $this->plan->optionPrefix,
                    $this->plan->slug,
                    $this->plan->vendor,
                ],
                $value
            );

            if ($rewritten !== $value) {
                $changed++;
                $value = $rewritten;
            }
        });

        return $changed > 0 ? 1 : 0;
    }

    /**
     * The replacements to apply to one file.
     *
     * ## Why the rules are per-file-profile
     *
     * A single global set of replacements is wrong in both directions. `App` must
     * be replaced with a word boundary everywhere it means the namespace, but
     * applying the namespace rule to `README.md` would rewrite the word "App"
     * in English prose. Conversely the text domain and CLI prefix need replacing
     * in `framework/`'s `__()` calls — which this script does not touch — but must
     * be replaced in any project file that carries them.
     *
     * So there are three profiles: **code** (PHP/JSON/stubs, everything), **docs**
     * (Markdown, only the identity strings, never the namespace rules) and
     * **tests** (code, plus the literal prefixes the suite hardcodes).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function replacementRules(string $relative): array
    {
        $isDocumentation = str_ends_with($relative, '.md');
        $isTest = str_starts_with($relative, 'tests/') || str_starts_with($relative, 'tests\\');

        $rules = [];

        // --- Identity strings, safe in every file kind -----------------------

        // The plugin name, as a whole phrase. `\b` on both sides keeps it from
        // matching inside a longer word, which matters for a name like "Lead"
        // that could appear as part of "Leader".
        $rules[] = [
            '/\b' . preg_quote('WP Mini Laravel', '/') . '\b/',
            $this->plan->name,
        ];

        // The slug. Deliberately *without* a trailing word boundary on the
        // right for the `wp-mini-laravel/v1` case, which the REST rule below
        // would otherwise miss — see the ordering note there.
        $rules[] = [
            '/' . preg_quote('wp-mini-laravel', '/') . '/',
            $this->plan->slug,
        ];

        // The option/transient/nonce prefix and the framework's error-code
        // prefix, which share a spelling. Applied as a bare substring because
        // it is always followed by an identifier: `wp_mini_laravel_flash`.
        $rules[] = [
            '/' . preg_quote('wp_mini_laravel_', '/') . '/',
            $this->plan->optionPrefix,
        ];

        // The constant prefix. `\b` after it would be wrong — the constant is
        // `WP_MINI_LARAVEL_BOOTED`, so the prefix is always followed by a word
        // character — which is why this is a bare substring with no boundary.
        $rules[] = [
            '/' . preg_quote('WP_MINI_LARAVEL_', '/') . '/',
            $this->plan->constantPrefix,
        ];

        // The CLI prefix. `wp_mini` is a *prefix* of the option prefix's `wp_mini_`,
        // so this must run **after** the option-prefix rule — which it does, by
        // position in this list. Reversed, `wp_mini_laravel_flash` would become
        // `<cli>_laravel_flash` and the option prefix would never match again.
        $rules[] = [
            '/\b' . preg_quote('wp_mini', '/') . '\b/',
            $this->plan->cliPrefix,
        ];

        // --- Namespace rules: code only, never documentation -----------------

        if (!$isDocumentation) {
            // ## The separator-escaping problem these four rules solve
            //
            // A namespace separator is written `\` in PHP source and `\\` inside
            // a JSON string. One pattern cannot match both by matching "a
            // backslash", because in JSON the separator is genuinely two
            // characters and consuming one leaves the other stranded: rewriting
            // the PSR-4 key `"Core\\"` with a pattern that eats a single
            // backslash produced `"LeadBridge\Core\"` — a lone `\C`, which is an
            // invalid JSON escape, and `composer dump-autoload` refused the file
            // the script had reported as rebranded. That is precisely the silent
            // failure the README's "what breaks quietly" section warns about.
            //
            // ## Why every backslash count is computed, never written
            //
            // A namespace separator passes through three layers on its way from
            // this source file to a rewritten `composer.json`, and each one
            // transforms it:
            //
            //   1. the PHP string literal in this file;
            //   2. `preg_replace`'s replacement parser, which collapses `\\` to `\`;
            //   3. the target file's own escaping — JSON spells one separator `\\`.
            //
            // A literal that is one backslash short produces a lone `\C` inside a
            // JSON string, which is an invalid escape — and that is not a
            // hypothetical. Three rounds of this script wrote `"LeadBridge\Core\"`
            // and reported success, and `composer dump-autoload` refused the file
            // afterwards. Hand-counting across three layers is the bug.
            //
            // So the counts are arguments to {@see backslashes()} and
            // {@see namespaceFor()}, and no rule contains a literal backslash run
            // longer than the one in its regex class.
            //
            // ## Why there is no capture group and no `$1`
            //
            // The natural design is `/\bCore([\\]{1,2})/` replaced with
            // `LeadBridge\Core$1`, re-emitting however many backslashes the file
            // used. It fails, and not for a reason worth fighting: `$1` arriving at
            // the right width depends on the same layering above, so the rule that
            // was meant to be escaping-agnostic becomes the one most sensitive to
            // it.
            //
            // Instead each namespace gets a rule per separator *width*, each
            // matching a fixed number and replacing with a fixed number. Two widths
            // because two files spell it differently — `core/Note/Domain/` says
            // `Core\Note\`, and `composer.json` says `"Core\\Note\\"` — and
            // rewriting each textually keeps a file's own escaping intact without
            // either needing to know about the other.

            // "App\\" -> "LeadBridge\\", one-separator and two-separator forms.
            $rules[] = [
                '/\bApp[' . self::regexClass() . ']{2}/',
                self::namespaceFor($this->plan->namespace, 2),
            ];
            $rules[] = [
                '/\bApp[' . self::regexClass() . ']/',
                self::namespaceFor($this->plan->namespace, 1),
            ];

            // "Core\\" -> "LeadBridge\\Core\\", the same two forms.
            //
            // ## Why the target namespace is excluded from the match
            //
            // These two rules are ordered widest-first, and the narrow one runs
            // *after* the wide one has already rewritten the text. That is a
            // problem the `App` rules do not have and the `Core` rules do, because
            // `<coreNamespace>` ends in the very word being matched: after the
            // two-separator rule turns `Core\\Note` into `LeadBridge\\Core\\Note`,
            // the one-separator rule still sees a `\bCore\\` — the one the previous
            // rule just inserted — and replaces it a second time.
            //
            // The result is `LeadBridge\\LeadBridge\\Core\\Note`, which is not a
            // class the project has. It reads as a namespace typo in hand-written
            // test code rather than as a rebrand defect, and it only appears where
            // `Core` is followed by exactly one separator in the *source*, which
            // in PHP is the two-separator-on-disk form: a single-quoted
            // `'Core\\GeneratedFixtures'`.
            //
            // So each rule refuses a match that is already preceded by the target
            // namespace. `(?<!...)` is a lookbehind, and `preg_quote` is used
            // because the namespace contains backslashes — the one place in this
            // file where escaping a pattern is the correct thing to do, since the
            // alternative is counting them by hand.
            // ## Why `Core` needs a guard and `App` does not
            //
            // The target namespace ends in the word being matched. `App` becomes
            // `LeadBridge`, which contains no `\bApp\` — so that pair is a fixed
            // point and running it twice changes nothing.
            //
            // `Core` becomes `LeadBridge\Core`, which *does* contain `\bCore\`.
            // Without a guard the rule re-fires on its own output, and because the
            // guard is a lookbehind it has to be fixed-length: PCRE rejects
            // `(?<!LeadBridge(?:\\\\)+)` outright with "lookbehind assertion is not
            // fixed length". Enumerating widths as an alternation works but grows
            // combinatorially and still has to guess a ceiling.
            //
            // ## The separator count, measured rather than reasoned about
            //
            // Two counts are in play and they are not the obvious ones.
            //
            // `namespaceFor($ns, $w)` returns `$ns` with every internal separator
            // repeated `$w` times, plus a trailing run. But `preg_replace`'s
            // *replacement* parser then collapses each `\\` in that string to a
            // single `\` — the same collapsing described in the class docblock —
            // so the text actually written carries exactly `$w` separators, not
            // `2 * $w`:
            //
            //     namespaceFor('LeadBridge\Core', 1)  ->  "LeadBridge\\Core\\"
            //     preg_replace output                 ->  "LeadBridge\Core\"
            //
            // The earlier version of this guard asserted the un-collapsed width,
            // so it demanded two separators of text that had one, never matched,
            // and let the rule re-fire on its own output — turning the prose
            // `autoloaded as Core\` into `LeadBridge\LeadBridge\Core\`, one copy
            // longer on every run of the script.
            //
            // Both rules emit a namespace that the other one can then re-match, so
            // the guard is the same for both and covers both emitted widths — the
            // one rule 8 writes (1 separator) and the one rule 7 writes (2).
            // Measured, not reasoned: `namespaceFor(..., 1)` produces two
            // backslashes that `preg_replace` collapses to one, and
            // `namespaceFor(..., 2)` produces four that collapse to two.
            //
            // A single-alternative guard cannot work here. Guarding at width 1
            // lets rule 7's two-separator output through, and guarding at width 2
            // lets rule 8's one-separator output through. PCRE requires lookbehind
            // alternatives to be individually fixed-length, which both are, so the
            // alternation is legal and needs no ceiling on the run length — there
            // are exactly two widths this script can emit.
            $ns = preg_quote($this->plan->namespace, '/');
            $emitted = $ns . self::backslashRun(1) . '|' . $ns . self::backslashRun(2);

            // ## The container guard, and why the rule cannot be type-blind here
            //
            // `Tests\Unit\Core\Note\` contains the token `Core\`, and it means
            // something completely different from the `Core\` in
            // `Core\Note\Domain\NoteEntity`. The first is a *path segment*: the
            // suite's PSR-4 root `Tests\` maps onto `tests/`, so `Core` there is
            // the directory name and must not move. The second is the project's
            // core namespace and must.
            //
            // A rule that matches `\bCore\` cannot tell them apart, and rewriting
            // the first is not a cosmetic error: `tests/Unit/Core/Note/` declares
            // `Tests\Unit\Core\Note`, and PSR-4 resolves that from the *path*. Rename
            // the namespace and leave the directory, and the class is no longer
            // findable — the suite dies with "Class ... not found" across every file
            // under `tests/Unit/Core/`, naming a namespace that looks like a typo in
            // the test rather than a rebrand defect.
            //
            // `Tests\` is the container, and it is the *only* one in this tree: the
            // project's own namespaces (`App\`, `Core\`) are mapped to `app/` and
            // `core/` by their own PSR-4 roots and sit at the top of a namespace, so
            // there is no enclosing token above them to key on. That asymmetry is
            // what makes the guard exact rather than a heuristic — a `Core` under
            // `Tests\` is always a path, and a `Core` not under it is always the
            // namespace.
            //
            // The guard must be *fixed-length literals*, and that constraint is
            // stronger than "no unbounded quantifier". Measured on this build:
            // any quantifier at all inside `(?<!...)` — `*`, `+`, `{0,8}`, even a
            // bounded one — compiles to "Internal error". So there is no way to
            // say "any depth of segments" here, and the guard enumerates the exact
            // container shapes that occur in this tree instead.
            //
            // The shape that matters is one segment between the PSR-4 root and the
            // `Core`: `Tests\Unit\Core\...`, which is where the suite's own
            // namespaces put a `Core` that must NOT be rewritten. Adding the
            // two-segment form covers a suite organised as `Tests\Feature\Unit\Core`
            // without reaching for a quantifier.
            //
            // An earlier attempt used `\bTests[\\]{1,2}.{0,64}` to allow any
            // depth. That is a compile error, `preg_replace` returned null, and
            // the null was cast to an empty string — so this script *wrote an
            // empty file* over every eligible path in the tree and reported a
            // successful rebrand. The rewrite loop now treats a null result as
            // fatal, so the failure mode is a stopped script rather than a
            // destroyed project; see {@see rewriteContents()}.
            $sep = self::regexClass();
            $tests = preg_quote('Tests', '/');

            $container = implode('|', [
                // Tests\Unit\Core\...
                $tests . $sep . 'Unit' . $sep,
                // Tests\\Unit\\Core\\... (the two-separator-on-disk form)
                $tests . $sep . $sep . 'Unit' . $sep . $sep,
                // Tests\Unit\Feature\Core\... and its doubled form
                $tests . $sep . 'Unit' . $sep . 'Feature' . $sep,
                $tests . $sep . $sep . 'Unit' . $sep . $sep . 'Feature' . $sep . $sep,
            ]);

            $rules[] = [
                '/'
                    . '(?<!' . $container . ')'
                    . '(?<!' . $emitted . ')'
                    . '\bCore[' . self::regexClass() . ']{2}/',
                self::namespaceFor($this->plan->coreNamespace, 2),
            ];
            $rules[] = [
                '/'
                    . '(?<!' . $container . ')'
                    . '(?<!' . $emitted . ')'
                    . '\bCore[' . self::regexClass() . ']/',
                self::namespaceFor($this->plan->coreNamespace, 1),
            ];

            // --- The bare forms, for `App::class` and a leading `\App` --------
            //
            // The negative lookahead is what keeps these from matching the `App`
            // in `Application` or the English word "Core" in prose: it refuses any
            // position where a backslash, letter, digit or underscore follows.
            $rules[] = [
                '/\bApp(?![\\\\A-Za-z0-9_])/',
                $this->plan->namespace,
            ];
            $rules[] = [
                '/\bCore(?![\\\\A-Za-z0-9_])/',
                $this->plan->coreNamespace,
            ];

            // The REST namespace, as a complete string. Runs after the slug rule,
            // which has already turned `wp-mini-laravel/v1` into `<slug>/v1` —
            // and when the user supplied a `--rest-namespace` that differs from
            // the derived `<slug>/v1`, this rule corrects it. Matching the
            // original string here would be matching something already gone.
            $rules[] = [
                '/\b' . preg_quote($this->plan->slug . '/v1', '/') . '\b/',
                $this->plan->restNamespace,
            ];

            // Config keys whose *values* are identifiers. These are the ones the
            // README's step 5 names, and they are matched as quoted strings so a
            // key named in a comment is not rewritten into an invalid array.
            $rules[] = [
                "/'name' => 'WP Mini Laravel'/",
                "'name' => '" . $this->plan->name . "'",
            ];
            $rules[] = [
                "/'namespace' => 'App'/",
                "'namespace' => '" . $this->plan->namespace . "'",
            ];
        }

        // --- Test-suite literals --------------------------------------------
        //
        // The README's checklist never mentions the test suite, and its own
        // verification step (`composer test`) depends on the suite passing. These
        // are the literals that would otherwise survive a rebrand and fail:
        //
        //   - `PluginBootTest` requires the entry file by path and asserts the
        //     booted constant, 11 times each.
        //   - `ResponseTest` hardcodes a flash transient key built from the
        //     option prefix.
        //
        // The temp-directory names in `ViewRendererTest` and friends are also
        // rewritten by the slug rule above. They are harmless either way, but
        // leaving them means a rebranded project still says `wp-mini-laravel` in
        // its temp paths, which looks like a botched job.
        if ($isTest) {
            $rules[] = [
                '/' . preg_quote('wp-mini-laravel.php', '/') . '/',
                $this->plan->slug . '.php',
            ];
        }

        return $rules;
    }

    // -------------------------------------------------------------- pass 2: paths

    /**
     * Rename the entry file to match the slug.
     *
     * ## Why the test that loads it is not broken by this
     *
     * `tests/Unit/PluginBootTest.php` builds the path as
     * `dirname(__DIR__, 2) . '/wp-mini-laravel.php'` — and the contents pass
     * rewrites that literal, because `.php` under `tests/` is eligible. The two
     * passes therefore agree: the file moves, and the only place naming it moves
     * with it. That ordering is stated in the class docblock for this reason.
     */
    private function renameEntryFile(): void
    {
        $from = $this->root . DIRECTORY_SEPARATOR . 'wp-mini-laravel.php';
        $to = $this->root . DIRECTORY_SEPARATOR . $this->plan->slug . '.php';

        if (!is_file($from)) {
            fwrite(STDOUT, sprintf(
                "\n  Entry file not found at %s — skipping the rename.\n"
                . "  (Expected on a second run: the file is already named after the slug.)\n",
                'wp-mini-laravel.php'
            ));

            return;
        }

        fwrite(STDOUT, sprintf("\n  Renaming entry file\n    wp-mini-laravel.php -> %s.php\n", $this->plan->slug));

        if (!$this->dryRun) {
            if (!@rename($from, $to)) {
                fwrite(STDERR, sprintf(
                    "\n  FAILED to rename the entry file. Rename it by hand:\n"
                    . "    wp-mini-laravel.php -> %s.php\n\n",
                    $this->plan->slug
                ));

                return;
            }
        }

        $this->changed[] = $this->plan->slug . '.php';
    }

    // ------------------------------------------------------------------- reports

    /**
     * Print what was found under `framework/` and deliberately left alone.
     *
     * ## Why this prints rather than stays silent
     *
     * Because the README's steps 7 and 8 tell the user to hand-edit these files,
     * which is the fork the README's own table warns against. A user who follows
     * those steps and then greps for the old name will find nothing outside
     * `framework/` and conclude the job is done; a user who greps *including*
     * `framework/` finds these and has to decide. Printing them names the
     * decision instead of leaving it to be discovered.
     *
     * Every entry is a fallback default: reached only when a config key is
     * missing, and this script has just set every key. They are correct as they
     * are — production library code falling back to its own name.
     */
    private function reportFrameworkDefaults(): void
    {
        fwrite(STDOUT, "\n  Left alone in framework/ (the library's own fallback defaults)\n");

        if ($this->frameworkDefaults === []) {
            fwrite(STDOUT, "    (none found)\n");

            return;
        }

        foreach ($this->frameworkDefaults as $file => $identifiers) {
            fwrite(STDOUT, sprintf(
                "    %-58s %s\n",
                $file,
                implode(', ', array_unique($identifiers))
            ));
        }

        fwrite(STDOUT, <<<'TXT'

    None of these are broken, and none are yours to rename:
    framework/ is a separate Composer package, and rewriting it forks the
    library. Two kinds are listed, and they behave differently:

      - Config fallback defaults (`config('app.cli_prefix', 'wp_mini')`, the
        `wp_mini_laravel_` prefix, the WP_Error codes). Reached only when
        config/app.php is missing a key, which after this rebrand it is not.
        Your values win.
      - Text domains in framework/ `__()` calls, and log-line prefixes. These
        ARE reached at runtime, so those few strings stay in the library's own
        translation domain. To translate them, add your own `__()` wrappers in
        app/ rather than editing the library.

TXT);
    }

    /**
     * Print what the user must run next.
     *
     * The last step is the one that makes every other step take effect, and
     * skipping it fails *silently* — Composer maps prefixes to directories and
     * does not check that a file declares the namespace it is filed under, so a
     * half-rebranded tree reports a successful dump-autoload and then boots
     * nothing. Naming it here is the only place it gets said.
     */
    private function reportNextSteps(): void
    {
        $actions = [
            'composer update && composer dump-autoload',
            'composer test',
            'composer test -- --filter PluginBootTest',
        ];

        fwrite(STDOUT, "\n  Next\n");

        foreach ($actions as $index => $action) {
            fwrite(STDOUT, sprintf("    %d. %s\n", $index + 1, $action));
        }

        fwrite(STDOUT, "\n    Step 1 is not optional: nothing above takes effect until the\n");
        fwrite(STDOUT, "    autoloader is rebuilt, and getting it wrong fails silently.\n");
        fwrite(STDOUT, "    Step 3 is the check that catches a broken namespace.\n");
    }

    // ---------------------------------------------------------- pass 1b: config fallbacks

    /**
     * Update the fallback defaults inside `config/app.php` so the `env()` calls
     * carry the new project's values.
     *
     * These are the second arguments of the `env($key, $default)` calls: the
     * values the project ships with by default and that a rebrand must move with
     * the identity, while the configuration keys themselves (APP_NAME,
     * APP_PREFIX, …) are static and stay as written.
     *
     * Only the seven keys that carry identity strings are rewritten here.
     * `env('APP_ENV', 'production')` keeps its production default because the
     * rebrand does not change the environment contract.
     */
    private function rewriteConfigFallbacks(): int
    {
        $path = $this->root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

        if (!is_file($path)) {
            return 0;
        }

        $original = (string) file_get_contents($path);
        $rewritten = $original;

        $mapping = [
            "'name' => env('APP_NAME', 'WP Mini Laravel')"
                => "'name' => env('APP_NAME', '" . $this->plan->name . "')",
            "'prefix' => env('APP_PREFIX', 'wp_mini_laravel_')"
                => "'prefix' => env('APP_PREFIX', '" . $this->plan->optionPrefix . "')",
            "'namespace' => env('APP_NAMESPACE', 'App')"
                => "'namespace' => env('APP_NAMESPACE', '" . $this->plan->namespace . "')",
            "'cli_prefix' => env('CLI_PREFIX', 'wp_mini')"
                => "'cli_prefix' => env('CLI_PREFIX', '" . $this->plan->cliPrefix . "')",
            "'rest_namespace' => env('REST_NAMESPACE', 'wp-mini-laravel/v1')"
                => "'rest_namespace' => env('REST_NAMESPACE', '" . $this->plan->restNamespace . "')",
        ];

        $changed = 0;

        foreach ($mapping as $old => $new) {
            if (str_contains($rewritten, $old)) {
                $rewritten = str_replace($old, $new, $rewritten);
                $changed++;
            }
        }

        if ($changed === 0) {
            return 0;
        }

        $relative = 'config/app.php';
        fwrite(STDOUT, sprintf(
            "    %-58s %d replacement%s\n",
            $relative,
            $changed,
            $changed === 1 ? ' ' : 's ' . $changed
        ));

        if (!$this->dryRun) {
            $this->writeFile($path, $rewritten);
        }

        $this->changed[] = $relative;

        return 1;
    }

    // --------------------------------------------------------- pass 1c: dotenv sync

    /**
     * Synchronise the dotenv files with the new identity.
     *
     * `.env.example` is updated unconditionally (it is the template and always
     * belongs to this repository), while `.env` is updated only when it exists
     * — on a fresh clone it is missing, so the caller copies the template.
     */
    private function rewriteDotenvFiles(): int
    {
        $changed = $this->rewriteDotenvFile('.env.example')
            | $this->rewriteDotenvFile('.env');

        return $changed;
    }

    /**
     * Update one dotenv file in place with the new identity values.
     *
     * Existing lines are preserved and only the known identity keys are
     * rewritten, so a project's own comments or additional keys survive. When a
     * key is missing entirely it is appended so the file stays a complete
     * template — this makes a second rebrand a no-op rather than a mutation.
     */
    private function rewriteDotenvFile(string $file): int
    {
        $path = $this->root . DIRECTORY_SEPARATOR . $file;

        if (!is_file($path)) {
            return 0;
        }

        $lines = (array) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $rebuild = false;
        $result = [];

        // Ordered longest-first so APP_NAME is handled before APP_* variants are
        // ever considered as a prefix — although the regex below is anchored to
        // the key itself, so the order is defensive rather than load-bearing.
        $mapping = [
            'APP_NAME'      => $this->plan->name,
            'APP_PREFIX'    => $this->plan->optionPrefix,
            'APP_NAMESPACE' => $this->plan->namespace,
            'CLI_PREFIX'    => $this->plan->cliPrefix,
            'REST_NAMESPACE' => $this->plan->restNamespace,
        ];

        foreach ($lines as $line) {
            $updated = false;

            foreach ($mapping as $key => $value) {
                if (preg_match('/^(' . preg_quote($key, '/') . ')\\s*=/i', $line, $m)) {
                    $result[] = sprintf('%s="%s"', $key, $value);
                    $updated = true;
                    $rebuild = true;
                    break;
                }
            }

            if (!$updated) {
                $result[] = $line;
            }
        }

        // Track which keys were already emitted so missing ones are appended only
        // once — this makes a second rebrand a no-op rather than a mutation.
        $emittedKeys = [];

        foreach ($lines as $line) {
            if (preg_match('/^([A-Z_]+)\s*=/i', $line, $m)) {
                $emittedKeys[$m[1]] = true;
            }
        }

        foreach ($mapping as $key => $value) {
            if (!isset($emittedKeys[$key])) {
                $result[] = sprintf('%s="%s"', $key, $value);
                $rebuild = true;
            }
        }

        if (!$rebuild) {
            return 0;
        }

        $relative = $file;
        fwrite(STDOUT, sprintf(
            "    %-58s updated\n",
            $relative
        ));

        if (!$this->dryRun) {
            file_put_contents($path, implode("\n", $result) . "\n");
        }

        $this->changed[] = $relative;

        return 1;
    }

    private function banner(): void
    {
        fwrite(STDOUT, "\n  WP Mini Laravel — rebrand\n\n");
        fwrite(STDOUT, sprintf("    name             %s\n", $this->plan->name));
        fwrite(STDOUT, sprintf("    slug             %s\n", $this->plan->slug));
        fwrite(STDOUT, sprintf("    vendor           %s\n", $this->plan->vendor));
        fwrite(STDOUT, sprintf("    namespace        %s\n", $this->plan->namespace));
        fwrite(STDOUT, sprintf("    core namespace   %s\n", $this->plan->coreNamespace));
        fwrite(STDOUT, sprintf("    constant prefix  %s\n", $this->plan->constantPrefix));
        fwrite(STDOUT, sprintf("    option prefix    %s\n", $this->plan->optionPrefix));
        fwrite(STDOUT, sprintf("    cli prefix       %s\n", $this->plan->cliPrefix));
        fwrite(STDOUT, sprintf("    rest namespace   %s\n", $this->plan->restNamespace));
        fwrite(STDOUT, sprintf("    text domain      %s\n", $this->plan->textDomain));
        fwrite(STDOUT, "\n");
    }

    // ----------------------------------------------------------------- discovery

    /**
     * Every file eligible for content rewriting, as absolute paths.
     *
     * @return list<string>
     */
    private function eligibleFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator(
                    $this->root,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
                ),
                function (\SplFileInfo $current): bool {
                    if (!$current->isDir()) {
                        return true;
                    }

                    // The framework boundary, enforced here — at the walk — rather
                    // than by filtering results afterwards. A directory that is
                    // never entered cannot be written to by any later change to
                    // the rules, which is what makes the boundary structural.
                    return !in_array($current->getFilename(), self::SKIP_DIRECTORIES, true);
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if (!$file->isFile() || !$file->isWritable()) {
                continue;
            }

            if (in_array($file->getFilename(), self::SKIP_FILES, true)) {
                continue;
            }

            $extension = strtolower($file->getExtension());

            if (!in_array($extension, self::TEXT_EXTENSIONS, true)) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /**
     * Scan `framework/` for the identifiers this script did *not* rewrite.
     *
     * ## Why this is its own walk rather than a branch in the main one
     *
     * Because the main walk never enters `framework/` — it is in
     * {@see SKIP_DIRECTORIES}, and `RecursiveCallbackFilterIterator` refuses the
     * directory before descending. A branch inside that loop checking for a
     * framework path is therefore unreachable, and the first version of this
     * class had exactly that bug: it printed "(none found)" while fourteen
     * occurrences sat three directories away. A report that is confidently wrong
     * is worse than no report, because it tells the user to stop looking.
     *
     * So the boundary is enforced in one place (the skip list) and *observed* in
     * another (here). Neither depends on the other being written a particular
     * way, which is what stops this regressing.
     */
    private function scanFrameworkDirectory(): void
    {
        $framework = $this->root . DIRECTORY_SEPARATOR . 'framework';

        if (!is_dir($framework)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($framework, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            $found = [];

            foreach (self::FRAMEWORK_DEFAULT_NEEDLES as $needle) {
                if (str_contains($contents, $needle)) {
                    $found[] = $needle;
                }
            }

            if ($found !== []) {
                $this->frameworkDefaults[$this->relative($file->getPathname())] = $found;
            }
        }

        ksort($this->frameworkDefaults);
    }

    /**
     * The regex fragment matching one literal backslash.
     *
     * Returned as text rather than written inline because `[\\]` inside a
     * double-quoted PHP string is a parser hazard: `"\\]"` is a valid escape and
     * `"[\\]"` is not, so the spelling changes depending on which quote the
     * enclosing expression used. One method, one spelling, one place to check.
     */
    private static function regexClass(): string
    {
        return '\\\\';
    }

    /**
     * The regex fragment matching exactly `$count` literal backslashes.
     *
     * ## Why this is a method and not `str_repeat('\\\\', $count)`
     *
     * `regexClass()` above returns the two-character fragment that matches one
     * backslash, and it exists because that fragment's spelling depends on the
     * enclosing quote style. This is the same fragment repeated, and it inherits
     * the same hazard plus one more: written inline inside a single-quoted string,
     * `'\\\\'` is four characters of pattern text, which PCRE reads as *two*
     * escaped backslashes — two literal backslashes, not the one that was meant.
     *
     * That off-by-one-factor-of-two is exactly the mistake this method removes
     * from the call site. Composing `regexClass()` keeps the unit spelled in one
     * place, so a caller asking for one gets one.
     *
     * @param int $count How many backslashes to match. Must be at least 1.
     */
    private static function backslashRun(int $count): string
    {
        return str_repeat(self::regexClass(), $count);
    }

    /**
     * A namespace, written so `preg_replace` emits it with the given number of
     * backslashes per separator.
     *
     * ## The arithmetic
     *
     * `preg_replace`'s replacement parser reads `\\` as one literal backslash, so
     * to emit *n* backslashes the string handed to it must contain *2n*. Each
     * backslash already in the namespace is one of those separators — the
     * namespace `LeadBridge\Core` has one — and the trailing separator after the
     * final segment is one more, supplied by `$trailing`.
     *
     * So the source string is `str_repeat('\\', n * 2)` per separator: with
     * `n = 2` that is four characters in the PHP string, which the parser
     * collapses to the two JSON needs.
     *
     * @param string $namespace A namespace, e.g. `LeadBridge\Core`.
     * @param int    $width     Backslashes to emit per separator: 1 for PHP
     *                          source, 2 for a JSON string.
     */
    private static function namespaceFor(string $namespace, int $width): string
    {
        // str_repeat is what removes the hand-counting: the number of characters
        // written here is computed from $width, not typed.
        $separator = str_repeat('\\', $width * 2);

        $out = '';

        foreach (str_split($namespace) as $char) {
            $out .= $char === '\\' ? $separator : $char;
        }

        return $out . $separator;
    }

    /**
     * A path relative to the root, with forward slashes.
     *
     * Forward slashes regardless of platform because these strings are printed
     * and compared — a Windows `tests\Unit\PluginBootTest.php` and a Linux
     * `tests/Unit/PluginBootTest.php` are the same file, and the `$isTest` check
     * in {@see replacementRules()} should not depend on which one is running.
     */
    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen($this->root) + 1));
    }

    /**
     * Whether a root-relative path lives inside the framework package.
     *
     * The framework is a separate Composer package with its own `composer.json`,
     * its own `Framework\` PSR-4 root, and its own name. Rebranding the project
     * does not rebrand the library: the library keeps its published identity so
     * that every install of it shares one, and the project's `composer.json`
     * requires it by that name.
     *
     * The main walk never descends into `framework/` at all — see
     * {@see SKIP_DIRECTORIES} — so this returns true for no file that walk
     * produces. It is called anyway, at the one place where being wrong would be
     * silently destructive, so that the boundary is enforced by a statement
     * rather than by an entry in a list three hundred lines away.
     */
    private function isUnderFramework(string $relative): bool
    {
        return str_starts_with($relative, 'framework/');
    }

    /**
     * Write a rewritten file, and take the process down if it cannot be written.
     *
     * `file_put_contents()` returns the byte count, or `false` on failure — a
     * read-only file, a full disk, a permissions problem. Ignoring that return
     * is the one failure mode this script must not have: `composer.json` would
     * stay on the old namespace while every file it maps moved to the new one,
     * the report would list the file as rebranded, and the user would find out
     * from a fatal `Class "App\..." not found` at runtime with no hint that a
     * write had silently failed.
     *
     * Dying mid-rebrand is bad, and this is still the right trade. It is loud,
     * it names the file, and it stops the script before it can rename the entry
     * file — the one step that makes the plugin fail to boot at all. Nothing
     * before this point has broken anything: a partially rewritten tree is a
     * recoverable one, and `git checkout .` is a complete recovery.
     *
     * @throws never Returns normally or terminates the process.
     */
    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) !== false) {
            return;
        }

        fwrite(STDERR, sprintf(
            "\n  FATAL: could not write %s\n"
            . "  The rebrand stopped here. No entry file was renamed.\n"
            . "  Fix the permissions on that file and re-run.\n\n",
            $this->relative($path)
        ));

        exit(1);
    }

    /**
     * How many lines differ between two versions of a file.
     *
     * A cheap approximation via `array_diff` on line arrays, used only for the
     * progress report. Not a diff — it counts changed lines in either direction,
     * so a rule that rewrote one line into two reports three. The number is a
     * "did anything surprising happen" signal, not a measurement.
     */
    private function countDiffLines(string $before, string $after): int
    {
        $beforeLines = explode("\n", $before);
        $afterLines = explode("\n", $after);

        return count(array_diff($beforeLines, $afterLines))
            + count(array_diff($afterLines, $beforeLines));
    }
}
