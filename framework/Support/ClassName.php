<?php
declare(strict_types=1);

namespace Framework\Support;

/**
 * Parses a class name that may carry a sub-namespace.
 *
 * ## Why this exists as its own value object
 *
 * The generators take a name like `Admin/CompanyController` and need three
 * different things from it: the class (`CompanyController`), the sub-namespace
 * to append to the generator's own (`Admin`), and the directory to create
 * (`Admin/`). Deriving those in three places is how a generated file ends up
 * declaring a namespace that does not match the directory it was written into —
 * a class Composer silently cannot autoload, in exactly the quiet way a wrong
 * PSR-4 map fails.
 *
 * So the input is parsed once, and everything that needs a piece of it asks the
 * parse. {@see \Framework\Console\GeneratorCommand::resolveClassName()} is the
 * only caller; keeping the rules here rather than inline is what lets the
 * base-class tests exercise the parse directly, without a generator.
 *
 * ## Separators
 *
 * Both `/` and `\` are accepted, because both are things a developer types.
 * Laravel accepts only `/`, but `\` is the namespace spelling of the same idea
 * and rejecting it would fail an invocation that has one obvious reading.
 * Leading and trailing separators, empty segments, and a `.php` suffix are all
 * trimmed rather than rejected — the same "interpret, don't refuse" rule the
 * generators already apply to bare names.
 *
 * ## What a segment may contain
 *
 * Each segment must be a valid PHP label and must start with an upper-case
 * letter or an underscore. The case rule is not cosmetic: the whole point of
 * `Admin/CompanyController` is that `Admin` names a *namespace*, indistinguishable
 * from the class name the caller also had to type. Requiring upper-case means
 * `admin/CompanyController` is refused with a message that says so, rather than
 * quietly scaffolded into a namespace no other file in the project spells that
 * way. That is the single case where a user-input class name can be *checked*,
 * and it is worth the strictness. Lower-case callers exist — `make:migration`
 * derives its class from a snake_case description — and they opt out through
 * {@see parseLenient()}.
 */
final class ClassName
{
    /**
     * @param string $class  The final segment — always a usable PHP class name.
     * @param string $sub    The sub-namespace, `\`-joined and without a leading
     *                       or trailing separator; `''` when the caller gave a
     *                       bare name.
     * @param string $path   The same sub-namespace as a relative directory path
     *                       (`Admin/Reports`); `''` when there is none.
     */
    private function __construct(
        public readonly string $class,
        public readonly string $sub,
        public readonly string $path,
    ) {
    }

    /**
     * Parse a caller-supplied name, enforcing the sub-namespace case rule.
     *
     * @throws \InvalidArgumentException When a segment is not usable as a
     *                                   namespace or class name.
     */
    public static function parse(string $name): self
    {
        return self::split($name, enforceCase: true);
    }

    /**
     * Parse without the case rule.
     *
     * For a generator whose input is not a class name at all — `make:migration`
     * takes a free-form description and derives the class itself — so applying
     * a rule about how class segments are spelled would reject input that is
     * meaningful and about to be rewritten anyway.
     *
     * @throws \InvalidArgumentException When a segment is not usable as a class
     *                                   name at all.
     */
    public static function parseLenient(string $name): self
    {
        return self::split($name, enforceCase: false);
    }

    /**
     * The sub-namespace as a directory path with a trailing separator, ready to
     * concatenate onto a target directory — `'Admin/'`, or `''` for a bare name.
     *
     * The trailing separator is included because every caller wants
     * `$directory . $sub . $class . '.php'`, and a method that returned
     * `'Admin'` would put that decision back in each of them.
     */
    public function pathPrefix(): string
    {
        return $this->path === '' ? '' : $this->path . '/';
    }

    /**
     * The sub-namespace as a namespace prefix with a trailing separator —
     * `'Admin\'`, or `''` for a bare name.
     */
    public function namespacePrefix(): string
    {
        return $this->sub === '' ? '' : $this->sub . '\\';
    }

    private static function split(string $name, bool $enforceCase): self
    {
        // A `.php` suffix is stripped here rather than in the generators, so
        // `Admin/CompanyController.php` and `Admin/CompanyController` are the
        // same request. It is removed before splitting so a name that is *only*
        // a suffix (`Foo.php` → `Foo`) keeps its segment.
        //
        // Only `/` and `\` split. A space or a dash is not a namespace separator
        // — a description like `create leads table` is one segment, which the
        // generator that receives it is free to normalize however it likes.
        $normalized = (string) preg_replace('/\.php$/i', '', trim($name));

        // Runs of separators produce one split; empty segments are dropped, so
        // `Admin//Company` and `/Admin/Company/` are the same request as the
        // clean spelling.
        $segments = array_values(array_filter(
            (array) preg_split('/[\\\\\/]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY),
            static fn (string $segment): bool => $segment !== ''
        ));

        if ($segments === []) {
            throw new \InvalidArgumentException('The name is empty.');
        }

        $class = (string) array_pop($segments);

        // The lenient path stops here. It exists for input that is not a class
        // name at all, so it has nothing to hold the segments to: whether
        // `create leads table` is acceptable is the receiving generator's call,
        // and it is about to rewrite the string anyway. Rejecting it here would
        // refuse input that generator handles correctly.
        if (! $enforceCase) {
            return new self($class, implode('\\', $segments), implode('/', $segments));
        }

        foreach ([...$segments, $class] as $segment) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $segment) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'The [%s] segment is not usable as a class or namespace name.',
                    $segment
                ));
            }

            if (preg_match('/^[A-Z_]/', $segment) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'The [%s] segment must start with an upper-case letter, so it reads as the class or namespace it names.',
                    $segment
                ));
            }
        }

        return new self($class, implode('\\', $segments), implode('/', $segments));
    }
}
