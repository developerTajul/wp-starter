<?php
declare(strict_types=1);

namespace WpMiniLaravel\Init;

/**
 * The set of identifiers a rebrand will produce, and the rules they must satisfy.
 *
 * Why identity and validity live in one class
 *
 * Because the derived values are only correct if the identity they came from is,
 * and splitting the two means validation has to be re-run after derivation -
 * the point at which it is easiest to forget. A `RebrandPlan` either exists and
 * is valid or does not exist at all; there is no half-checked state for the
 * rebrander to be handed.
 *
 * Why the derived defaults are what they are
 *
 * The README's table gives the convention for each identifier, and the defaults
 * here implement it: a REST namespace of `<slug>/v1`, a CLI prefix that is the
 * namespace lowercased, an option prefix that is the slug snake_cased. A user
 * supplying only the four required values therefore gets a project that follows
 * every convention, without having to have read the README to know what they
 * were.
 *
 * That matters more than it looks. Every one of these names is *derived* from
 * something the user did think about - the slug, the namespace - so the derived
 * value is spelled correctly by construction. The alternative is a user inventing
 * `--option-prefix=LeadBridge_` on a Tuesday, which works until the first
 * `strtolower()` comparison somewhere.
 */
final class RebrandPlan
{
    /**
     * Identifiers that must not survive into a rebranded project.
     *
     * Why this list is enforced rather than trusted
     *
     * A rebrand to `--namespace=App` is a rebrand that does nothing: every
     * replacement is a no-op, the script reports success, and the project keeps
     * the default identity that two other plugins in the same site may also
     * have. It is the single most likely mistake - "App" is what the README's
     * own table calls the default - and it fails completely silently.
     *
     * The same reasoning applies to every default identifier: rebranding to the
     * value you are replacing means the four globally-unique names the README
     * warns about collide with any other un-rebranded copy of this starter.
     *
     * @var list<string>
     */
    private const FORBIDDEN = [
        'app',
        'core',
        'wp-mini-laravel',
        'wp_mini_laravel',
        'WP_MINI_LARAVEL',
        'devlinkstudios',
        'wp_mini',
        'framework',
    ];

    /**
     * @param string $name            Human-readable plugin name.
     * @param string $slug            Plugin folder / entry-file stem.
     * @param string $vendor          Composer vendor.
     * @param string $namespace       Namespace root, without a trailing backslash. The
     *                                logical layers are kept *under* it: `App\` becomes
     *                                `<root>\App\` and `Core\` becomes `<root>\Core\`.
     * @param string $appNamespace    The application layer namespace, `<root>\App`.
     * @param string $coreNamespace   The domain layer namespace, `<root>\Core`.
     * @param string $constantPrefix  PHP constant prefix, with trailing underscore.
     * @param string $optionPrefix    Option/transient/nonce prefix, with trailing underscore.
     * @param string $cliPrefix       WP-CLI command namespace.
     * @param string $restNamespace   REST namespace, e.g. `lead-bridge/v1`.
     * @param string $textDomain      i18n text domain.
     */
    private function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $vendor,
        public readonly string $namespace,
        public readonly string $appNamespace,
        public readonly string $coreNamespace,
        public readonly string $constantPrefix,
        public readonly string $optionPrefix,
        public readonly string $cliPrefix,
        public readonly string $restNamespace,
        public readonly string $textDomain,
    ) {
    }

    /**
     * Build a plan from parsed command-line options, deriving what was omitted.
     *
     * Why the layers are prefixed rather than replaced
     *
     * `App` and `Core` name *layers* - delivery and domain - and those names are
     * part of the architecture the starter teaches, not part of its identity. A
     * rebrand therefore moves the root and keeps the layers: `App\Database`
     * becomes `Acme\App\Database`, not `Acme\Database`. Flattening them would make
     * every rebranded project's namespace scheme disagree with every hexagonal
     * reference the README points at, and it would erase the `core/` ↔ `app/`
     * distinction from the namespace itself - the one place a reader of a `use`
     * statement can see which zone a class belongs to.
     *
     * @param array<string, string|bool> $options
     */
    public static function fromOptions(array $options): self
    {
        $namespace = self::trimNamespace((string) $options['namespace']);
        $slug = trim((string) $options['slug']);

        return new self(
            name: trim((string) $options['name']),
            slug: $slug,
            vendor: trim((string) $options['vendor']),
            namespace: $namespace,
            appNamespace: $namespace . '\\App',
            coreNamespace: self::trimNamespace(
                (string) ($options['core-namespace'] !== ''
                    ? $options['core-namespace']
                    : $namespace . '\\Core')
            ),
            constantPrefix: self::prefix(
                (string) $options['constant-prefix'],
                self::upperSnake($namespace) . '_'
            ),
            optionPrefix: self::prefix(
                (string) $options['option-prefix'],
                self::lowerSnake($slug) . '_'
            ),
            cliPrefix: (string) ($options['cli-prefix'] !== ''
                ? trim((string) $options['cli-prefix'])
                : strtolower(str_replace(['\\', '_'], '', $namespace))),
            restNamespace: (string) ($options['rest-namespace'] !== ''
                ? trim((string) $options['rest-namespace'])
                : $slug . '/v1'),
            textDomain: (string) ($options['text-domain'] !== ''
                ? trim((string) $options['text-domain'])
                : $slug),
        );
    }

    /**
     * Every way this plan is unusable, as human-readable sentences.
     *
     * Returns a list rather than throwing on the first problem, so a user sees
     * every mistake in one pass. The rules are all about *silent* failures -
     * nothing here would produce an error at rebrand time, only a plugin that
     * does not load or two plugins that overwrite each other's options.
     *
     * @return list<string>
     */
    public function validate(): array
    {
        $problems = [];

        $problems = [...$problems, ...$this->validateSlug()];
        $problems = [...$problems, ...$this->validateVendor()];
        $problems = [...$problems, ...$this->validateNamespace()];
        $problems = [...$problems, ...$this->validatePrefixes()];
        $problems = [...$problems, ...$this->validateRestNamespace()];
        $problems = [...$problems, ...$this->validateUniqueness()];

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function validateSlug(): array
    {
        if ($this->slug === '') {
            return ['--slug must not be empty.'];
        }

        // WordPress folder names are case-insensitive on Windows, case-sensitive
        // on Linux, and the plugin *file* name is what WordPress shows in the
        // plugins list. A slug with a capital letter works on the developer's
        // machine and produces a plugin WordPress lists under a different name
        // on the server.
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $this->slug) !== 1) {
            return [sprintf(
                '--slug "%s" is not a valid plugin slug. Use lowercase letters, digits and single '
                . 'hyphens, e.g. "lead-bridge". WordPress uses this as a directory name, and it '
                . 'is compared case-sensitively on Linux hosts.',
                $this->slug
            )];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function validateVendor(): array
    {
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $this->vendor) !== 1) {
            return [sprintf(
                '--vendor "%s" is not valid. Composer vendors are lowercase, hyphenated, e.g. "acme".',
                $this->vendor
            )];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function validateNamespace(): array
    {
        $problems = [];

        foreach ([[$this->namespace, '--namespace'], [$this->coreNamespace, '--core-namespace']] as [$value, $flag]) {
            if (preg_match('/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)*$/', $value) !== 1) {
                $problems[] = sprintf(
                    '%s "%s" is not a valid PHP namespace root. Each segment must start with a '
                    . 'capital letter and contain only letters and digits, e.g. "LeadBridge" or '
                    . '"Acme\\Plugin".',
                    $flag,
                    $value
                );
            }
        }

        // The core namespace must be *under* the app namespace, because
        // `composer.json` maps both and a core namespace outside it would be a
        // second PSR-4 root pointing at the same directory - which Composer
        // accepts and which then resolves whichever prefix matches first.
        if (!str_starts_with($this->coreNamespace, $this->namespace . '\\')) {
            $problems[] = sprintf(
                '--core-namespace "%s" must sit under --namespace "%s", e.g. "%s\\Core". It maps '
                . 'to the same PSR-4 directory, and a namespace outside that root is a prefix '
                . 'Composer resolves against the wrong path.',
                $this->coreNamespace,
                $this->namespace,
                $this->namespace
            );
        }

        // The root must not already name a layer. The rules *append* the layer
        // to the root, so `--namespace=LeadBridge\App` yields
        // `LeadBridge\App\App\...` - namespaces no file declares, which
        // Composer therefore cannot resolve, and which fail exactly as quietly
        // as a wrong PSR-4 map. The flag means "root"; the usage text says so,
        // and this makes the mistake impossible to make anyway.
        $separator = strrpos($this->namespace, '\\');
        $lastSegment = $separator === false
            ? $this->namespace
            : substr($this->namespace, $separator + 1);

        if (in_array(strtolower($lastSegment), ['app', 'core'], true)) {
            $root = $separator === false ? '' : substr($this->namespace, 0, $separator);

            $problems[] = sprintf(
                '--namespace "%s" already ends with a layer name. It is the *root* the layers are '
                . 'prefixed with, so `App\` becomes "<root>\App\", passing "%s" produces "%s\App\". '
                . 'Pass "%s" instead.',
                $this->namespace,
                $lastSegment,
                $this->namespace,
                $root !== '' ? $root : '<Root>'
            );
        }

        // The two layers must stay distinct. They are separate PSR-4 roots
        // pointing at `app/` and `core/`; giving them the same prefix makes
        // Composer keep one key and silently drop the other, so every class in
        // the lost directory stops resolving.
        if (strcasecmp($this->coreNamespace, $this->appNamespace) === 0) {
            $problems[] = sprintf(
                '--core-namespace "%s" must differ from the application layer namespace "%s". '
                . 'They are separate PSR-4 roots for separate directories.',
                $this->coreNamespace,
                $this->appNamespace
            );
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function validatePrefixes(): array
    {
        $problems = [];

        foreach (
            [
                [$this->constantPrefix, '--constant-prefix', '/^[A-Z][A-Z0-9]*(_[A-Z0-9]+)*_$/'],
                [$this->optionPrefix, '--option-prefix', '/^[a-z][a-z0-9]*(_[a-z0-9]+)*_$/'],
                [$this->cliPrefix, '--cli-prefix', '/^[a-z][a-z0-9]*$/'],
            ] as [$value, $flag, $pattern]
        ) {
            if (preg_match($pattern, $value) !== 1) {
                $problems[] = sprintf(
                    '%s "%s" is not a valid prefix. See the usage text for the expected shape.',
                    $flag,
                    $value
                );
            }
        }

        return $problems;
    }

    /**
     * @return list<string>
     */
    private function validateRestNamespace(): array
    {
        // WordPress requires a vendor segment: a route registered under a bare
        // name is published at /wp-json/{name}/, and a namespace without a slash
        // is rejected by register_rest_route() with a `rest_invalid_handler`
        // error that names the namespace but not the route that caused it.
        if (preg_match('#^[a-z0-9-]+(/[a-z0-9.-]+)+$#', $this->restNamespace) !== 1) {
            return [sprintf(
                '--rest-namespace "%s" is not valid. WordPress requires at least a vendor and a '
                . 'version, e.g. "lead-bridge/v1".',
                $this->restNamespace
            )];
        }

        return [];
    }

    /**
     * Identifiers that are the defaults this script exists to replace.
     *
     * @return list<string>
     */
    private function validateUniqueness(): array
    {
        $problems = [];

        foreach (
            [
                ['--namespace', $this->namespace],
                ['--core-namespace', $this->coreNamespace],
                ['--slug', $this->slug],
                ['--text-domain', $this->textDomain],
                ['--vendor', $this->vendor],
                ['--cli-prefix', $this->cliPrefix],
                ['--option-prefix', rtrim($this->optionPrefix, '_')],
                ['--constant-prefix', rtrim($this->constantPrefix, '_')],
                ['--rest-namespace', $this->restNamespace],
            ] as [$flag, $value]
        ) {
            foreach (self::FORBIDDEN as $forbidden) {
                if (strcasecmp($value, $forbidden) === 0) {
                    $problems[] = sprintf(
                        '%s is still the default value "%s". Rebranding to the value being replaced '
                        . 'changes nothing, the project would keep the identity this script exists '
                        . 'to remove, and two copies of the starter in one site would share options, '
                        . 'CLI commands and REST routes.',
                        $flag,
                        $value
                    );

                    break;
                }
            }
        }

        return $problems;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Strip a leading or trailing backslash from a namespace.
     *
     * A user copying `\App\` out of a `use` statement into `--namespace` would
     * otherwise get a plan whose every replacement produced `\\LeadBridge\`, and
     * PHP resolves that to the global namespace - which is where a class named
     * `LeadBridge\Core\Note\Domain\NoteEntity` would not be.
     */
    private static function trimNamespace(string $namespace): string
    {
        return trim($namespace, "\\ \t\n\r\0\x0B");
    }

    /**
     * Use a supplied prefix, or the derived one.
     */
    private static function prefix(string $supplied, string $derived): string
    {
        return trim($supplied) !== '' ? trim($supplied) : $derived;
    }

    /**
     * Normalize an identifier into an underscore-separated stem.
     *
     * `LeadBridge` -> `Lead_Bridge`; `lead-bridge` -> `lead_bridge`.
     *
     * Why the result is not upper- or lowercased here
     *
     * Because two different prefixes are derived from this stem and they need
     * **opposite** cases: the constant prefix is `LEAD_BRIDGE_` and the option
     * prefix is `lead_bridge_`. A helper that picked a case would have to be
     * called twice with a flag, which is the shape that gets called once with the
     * wrong one. Returning the neutral stem means the caller states the case it
     * wants - see {@see upperSnake()} and {@see lowerSnake()}.
     *
     * The two normalizations, and what each protects against
     *
     *  - **Hyphens, backslashes and spaces become underscores.** This is the rule
     *    that matters: a slug is the common input, and `lead-bridge` naively
     *    handled produces `LEAD-BRIDGE_` - a valid-looking string that is not a
     *    valid PHP constant prefix, because `define('LEAD-BRIDGE_BOOTED')` is
     *    parsed as the subtraction `LEAD - BRIDGE_BOOTED`.
     *  - **A lower-to-upper boundary gains an underscore**, so a namespace
     *    segment with an internal capital - `LeadBridge` - becomes `Lead_Bridge`
     *    rather than `LeadBridge`. Both uppercase to a valid prefix; only one
     *    gives `LEAD_BRIDGE_`, which is the convention the README's table shows
     *    and which a person reading `config/app.php` will expect.
     */
    private static function snakeStem(string $value): string
    {
        $value = str_replace(['\\', '-', ' '], '_', $value);

        return (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value);
    }

    /**
     * The uppercase form, for constants: `LEAD_BRIDGE`.
     */
    private static function upperSnake(string $value): string
    {
        return strtoupper(self::snakeStem($value));
    }

    /**
     * The lowercase form, for options, transients and nonces: `lead_bridge`.
     */
    private static function lowerSnake(string $value): string
    {
        return strtolower(self::snakeStem($value));
    }
}
