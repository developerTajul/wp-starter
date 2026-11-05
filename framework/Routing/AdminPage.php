<?php
declare(strict_types=1);

namespace Framework\Routing;

/**
 * A typed view over an admin route's WordPress-specific options.
 *
 * ## Why a value object rather than more methods on Route
 *
 * `Route` is shared by both domains and must not know what `menu_title` is —
 * a REST endpoint has no menu. The options bag is the escape hatch that keeps
 * `add_menu_page()`'s nine positional arguments from becoming nine first-class
 * methods on a DTO that mostly serves REST, and it works.
 *
 * What it does not do is make those options *readable*. A caller who wants the
 * menu title today writes:
 *
 *     (string) $route->getOption('menu_title', $route->getOption('page_title', 'Untitled'))
 *
 * — a default chain, a cast, and a string key, at every call site, with no
 * single place where the set of understood keys is written down. This class is
 * that place. It is what the adapter, `route:list` and a view rendering the
 * menu all read through, so they cannot disagree about what a key means or fall
 * back to different defaults.
 *
 * ## Read-only, and derived on construction
 *
 * Built from a route, never written back to it. A value object that could write
 * would need a way to report a partial success, and an admin page whose title
 * was set but whose capability was not is a state nothing can reason about. The
 * route keeps its options exactly as declared.
 *
 * ## Defaults are the ones WordPress would use
 *
 * Where a default is not simply this framework's choice — `manage_options` for
 * the capability, `''` for the icon — it is what `add_menu_page()` itself
 * defaults to, so an unset option and an omitted argument behave identically.
 * That equivalence is what makes the options bag safe to leave partially filled.
 */
final class AdminPage
{
    /**
     * @param array<string, mixed> $options
     */
    private function __construct(
        private readonly string $slug,
        private readonly array $options
    ) {
    }

    public static function fromRoute(Route $route): self
    {
        return new self($route->getPath(), $route->getOptions());
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromArray(string $slug, array $options = []): self
    {
        return new self($slug, $options);
    }

    /**
     * The menu slug — what appears in `admin.php?page={slug}`.
     *
     * Returned separately from the options because it is the route's path, not an
     * option: the path is what identifies the screen, and duplicating it into the
     * bag would create two places to disagree.
     */
    public function slug(): string
    {
        return $this->slug;
    }

    /**
     * The title shown in the browser tab and the page heading.
     *
     * Falls back to the menu title before falling back to the slug, because a
     * page that declares only `menu_title` has said what it wants the header to
     * say — humanizing the slug over the top of that would ignore a deliberate
     * choice.
     */
    public function pageTitle(): string
    {
        // Both branches go through `string()`, which is what rejects a non-string
        // value rather than casting it. Testing `has()` first and then reading with
        // a `''` default — as the naive form does — returns the empty string for a
        // title given as an array, because `has()` is true and the read fails: the
        // page then renders with a blank heading instead of falling back.
        $title = $this->string('page_title');

        if ($title !== '') {
            return $title;
        }

        $menuTitle = $this->string('menu_title');

        return $menuTitle !== '' ? $menuTitle : self::humanize($this->slug);
    }

    /**
     * The title shown in the admin menu itself.
     */
    public function menuTitle(): string
    {
        $title = $this->string('menu_title');

        return $title !== '' ? $title : $this->pageTitle();
    }

    /**
     * The capability a user must have to see and open this screen.
     *
     * `manage_options` by default, matching `add_menu_page()`. Deliberately a
     * default rather than an error when unset: an admin screen with no capability
     * declared is the common case and is correctly restricted, whereas refusing
     * to register would make the common case the broken one.
     *
     * Note that this is WordPress's check, made before the route's own middleware
     * runs. It is the outer gate — middleware is for what capabilities cannot
     * express, such as a per-record ownership check.
     */
    public function capability(): string
    {
        return $this->string('capability', 'manage_options');
    }

    /**
     * The dashicon or image URL for the menu entry. Empty for none.
     */
    public function icon(): string
    {
        return $this->string('icon');
    }

    /**
     * Where in the menu this entry sits, or null for WordPress's own placement.
     *
     * Null rather than a number, because WordPress distinguishes "not given"
     * (append, resolving collisions itself) from a position, and passing `0` or a
     * default integer would pin the entry somewhere the author never asked for.
     */
    public function position(): ?int
    {
        $position = $this->options['position'] ?? null;

        if (is_int($position)) {
            return $position;
        }

        // A position is often written as a string in a config file. Accepted when
        // it is a clean integer, refused otherwise rather than cast — `(int)
        // 'top'` is 0, which would silently pin the entry to the top of the menu.
        return is_string($position) && preg_match('/^-?\d+$/', $position) === 1
            ? (int) $position
            : null;
    }

    /**
     * The parent menu slug when this is a submenu entry, or null for top level.
     *
     * `null` and `''` are the same thing here: `add_submenu_page()` with an empty
     * parent would register an orphan the menu never renders, so the empty string
     * is treated as "no parent" rather than passed through.
     */
    public function parentSlug(): ?string
    {
        $parent = $this->options['parent_slug'] ?? null;

        return is_string($parent) && $parent !== '' ? $parent : null;
    }

    public function isSubmenu(): bool
    {
        return $this->parentSlug() !== null;
    }

    /**
     * The `admin_post_{action}` name, when this route is a form handler.
     *
     * Written back onto the route by the adapter at flush() time, so a view
     * rendering a form's hidden `action` field can read the name WordPress will
     * actually fire rather than deriving it a second time. Null until then.
     */
    public function action(): ?string
    {
        $action = $this->options['action'] ?? null;

        return is_string($action) && $action !== '' ? $action : null;
    }

    /**
     * Whether a key was given a usable value.
     *
     * A key present but null, or present as an empty string, counts as not given
     * — that is how a config that writes `'icon' => ''` means "no icon", and
     * treating it as given would let an empty string beat a real default.
     */
    public function has(string $key): bool
    {
        $value = $this->options[$key] ?? null;

        return $value !== null && $value !== '';
    }

    /**
     * Every option, unchanged.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    /**
     * The title-case label for a slug, when nothing else was given.
     *
     * `lead-reports` becomes `Lead reports` — only the first word capitalised,
     * because an unset label is a fallback and over-capitalising "Reports And
     * Settings" reads worse than the plain sentence case. An author who wants
     * different words sets `menu_title`.
     */
    private static function humanize(string $slug): string
    {
        $words = preg_split('/[-_\s]+/', trim($slug, '/')) ?: [];
        $words = array_values(array_filter($words, static fn (string $w): bool => $w !== ''));

        if ($words === []) {
            // A slug of only separators — `---` — would otherwise produce an
            // empty title, which WordPress renders as a blank menu entry with no
            // way to tell which route made it.
            return $slug === '' ? 'Untitled' : $slug;
        }

        $words[0] = ucfirst($words[0]);

        return implode(' ', $words);
    }

    /**
     * A string option, or $default when it is absent or not a string.
     *
     * Not cast: a `menu_title` given as an array is a mistake, and `(string)`
     * on it raises a notice and yields "Array" — a menu entry literally labelled
     * "Array". Falling back to the default keeps the menu renderable while the
     * wrong value goes nowhere.
     */
    private function string(string $key, string $default = ''): string
    {
        $value = $this->options[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
