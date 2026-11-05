<?php
declare(strict_types=1);

namespace Framework\Support;

use Framework\Http\Exceptions\HttpException;
use Framework\Http\Responses\HtmlResponse;
use Framework\Http\Responses\RedirectResponse;
use Framework\Http\Responses\ViewResponse;

/**
 * Turns a view *name* into a rendered page.
 *
 * ## The gap this fills
 *
 * {@see ViewResponse} renders a PHP file, but it takes an **absolute path**. A
 * controller that wants one therefore has to build that path itself —
 * `base_path('views/leads/index.php')` — which is the moment a controller starts
 * knowing where the project keeps its files. That is a delivery concern sitting
 * in the wrong layer, and it is the one thing a second project would have to
 * change in every action it wrote.
 *
 * This class owns that knowledge. A controller asks for `'leads.index'` and gets
 * back something it can hand to WordPress:
 *
 *     return $this->view('leads.index', ['leads' => $leads]);
 *
 * ## Why it is a service and not a helper function
 *
 * The dot-to-slash lookup is the trivial part. What makes this worth resolving
 * from the container is that it also carries the **view root**, resolved once
 * from `config('app.views')`, and a **shared bag** — data every view in the
 * project receives. A helper function would have to reach for config on every
 * call and would leave the shared bag in a global.
 *
 * ## What it does not do
 *
 * No layout engine, no template syntax, no compilation, no cache. A view is a
 * plain PHP file and the renderer includes it. Every one of those features is a
 * second language to learn and a compile step to debug, and the thing that
 * actually makes views pleasant to write — escaping, composition, and knowing
 * which variables exist — is available in plain PHP the moment the escaping is
 * made convenient. See {@see e()} and {@see render()}.
 */
class ViewRenderer
{
    /**
     * Data merged into every view this renderer renders.
     *
     * @var array<string, mixed>
     */
    private array $shared = [];

    /**
     * The absolute view root, resolved once.
     *
     * Resolved lazily rather than in the constructor because `app.views` is read
     * through the static {@see \Framework\Config\Config} store, which a project
     * populates at boot — a renderer resolved by a provider's `register()` would
     * otherwise capture the default before the project had its say. Cached after
     * the first read, because a render loop calling `config()` per view is one
     * global lookup per view for a value that cannot change mid-request.
     */
    private ?string $root = null;

    // ------------------------------------------------------------------ rendering

    /**
     * Render a view to a string.
     *
     * `$data` wins over the shared bag, which wins over the request's flashed
     * payload — most specific last, so an action that names a variable gets the
     * value it named.
     *
     * @param array<string, mixed> $data
     *
     * @throws HttpException When the view is missing,
     *                                                  or raises while rendering.
     */
    public function render(string $view, array $data = []): string
    {
        return $this->make($view, $data)->render();
    }

    /**
     * Build a view response without rendering it.
     *
     * What a controller returns. Nothing is rendered until `send()` — or until a
     * test calls `render()` — which is what keeps a controller's return value a
     * value rather than a side effect.
     *
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function make(string $view, array $data = [], int $status = 200, array $headers = []): ViewResponse
    {
        return new ViewResponse(
            $this->path($view),
            $this->gather($data),
            $status,
            $headers
        );
    }

    /**
     * Resolve a view name to an absolute path.
     *
     * `'leads.index'` -> `<root>/leads/index.php`. Slashes work too, so a project
     * that prefers `'leads/index'` is not fighting the renderer, and a trailing
     * `.php` is accepted either way — `'leads.index'`, `'leads/index'` and
     * `'leads.index.php'` all name the same file.
     *
     * ## Why a literal-filename fallback exists
     *
     * Dots mean *both* "directory separator" and "character in a filename", and
     * the two are genuinely indistinguishable: `receipt.v2.php` could be a file
     * with that name or the file `receipt/v2.php`. Resolving dotted-first is what
     * keeps the convention self-consistent, but it makes the literal name
     * unreachable — so when the dotted reading finds nothing, the name is tried
     * verbatim. Both readings are checked against the filesystem rather than
     * guessed from the shape of the string, which is the only way to tell them
     * apart.
     *
     * The fallback needs the whole trailing filename, so `receipt.v2.php` is
     * reachable — but only when written *with* the extension. `receipt.v2` is not
     * a name the literal reading ever sees, because the extension is appended
     * before the lookup and the fallback then looks for a file called
     * `receipt.v2`, which is not what was written. **A literal filename must be
     * spelled with its `.php` to reach the fallback.**
     *
     * ## The one thing that cannot work
     *
     * **A directory whose name contains a dot is unreachable.** `v1.2/index.php`
     * cannot be named: `'v1.2.index'` resolves to `<root>/v1/2/index.php`, and
     * the slash spelling `'v1.2/index'` does not help, because the dot in the
     * final segment is substituted exactly like the others —
     * `<root>/v1/2/index.php` again. Only a name written *with* its extension
     * survives, and then only via the literal fallback: `'v1.2/index.php'` finds
     * the file, because `v1/2/index.php` misses and `v1.2/index.php` is then
     * tried verbatim.
     *
     * That is a limitation of the dotted convention rather than of this
     * implementation, and the practical answer is not to put a dot in a view
     * directory's name. It is pinned by a test so it is documented rather than
     * rediscovered from a 404.
     *
     * ## Why a parent segment is refused
     *
     * View names routinely come from data — a settings screen storing "which
     * template", a theme-style override table — and `../` in one is how a view
     * name becomes an arbitrary file read. Normalizing would silently load
     * *something*; refusing names the mistake. See {@see escapesRoot()} for why
     * the check has to happen on the raw string.
     *
     * @throws HttpException When the name escapes the
     *                                                  view root or resolves to a
     *                                                  file that is not there.
     */
    public function path(string $view): string
    {
        $relative = str_replace('\\', '/', $view);

        if ($this->escapesRoot($relative)) {
            throw new HttpException(sprintf(
                'View [%s] resolves outside the view root. A view name may not contain a parent '
                . 'directory segment.',
                $view
            ));
        }

        // Even a name that already ends in `.php` is read dotted-first, because
        // that is what keeps the convention self-consistent: `leads.index` and
        // `leads.index.php` have to name the same file, and only the dotted
        // reading makes them. `leads.index.php` is also the spelling a config
        // file or a database column is most likely to hold.
        $dotted = $this->normalize(preg_replace('/\.php$/i', '', $relative) ?? $relative) . '.php';

        $path = base_path_join($this->root(), $dotted);

        if (is_file($path)) {
            return $path;
        }

        // Then the name verbatim, as a filename. This is what lets a caller hand
        // over a real filename whose dots are *not* separators —
        // `receipt.v2.php`, `report.final.php` — which the dotted reading would
        // otherwise turn into `receipt/v2.php` and fail to find. Consulted only
        // after the dotted reading misses, so the dotted convention keeps its
        // meaning everywhere it can.
        $literal = base_path_join($this->root(), ltrim($relative, '/'));

        if (is_file($literal)) {
            return $literal;
        }

        // Checked here rather than left to ViewResponse so the error names the
        // *view* the caller asked for. The renderer's own message can only name
        // the path it was handed, which by then is a file the caller never wrote
        // a name for — and "views/leads/indx.php is missing" is a worse bug
        // report than "view [leads.indx] is missing".
        //
        // Both candidates are named. A miss here means *two* different files were
        // looked for, and which one the caller meant is exactly the thing they
        // got wrong: a dotted name that should have been a literal filename (or
        // the reverse) is invisible when the report lists only the dotted reading,
        // and looks like a plain "file not found" in a directory they did not
        // mean to address.
        throw HttpException::viewNotFound($view, $dotted, $literal);
    }

    /**
     * Render a view and return it wrapped in a response.
     *
     * The form a controller returns for a front-end page or a short admin screen.
     * Rendering happens eagerly here rather than at `send()`, so a view that
     * throws does so inside the action — where the caller still owns the response
     * — instead of part-way through WordPress's output.
     *
     * @param array<string, mixed> $data
     */
    public function respond(string $view, array $data = [], int $status = 200): HtmlResponse
    {
        return new HtmlResponse($this->render($view, $data), $status);
    }

    /**
     * Whether a view exists, without rendering it.
     *
     * The check a project needs for a template-override chain: look for the
     * project's view, fall back to the plugin's, fall back to the framework's.
     * Deliberately quiet about *why* a name is unusable — `..` reports false
     * here rather than throwing, because this method's contract is "can I render
     * this", and the answer to a name that escapes the root is no.
     */
    public function exists(string $view): bool
    {
        try {
            $this->path($view);
        } catch (HttpException) {
            return false;
        }

        return true;
    }

    // ----------------------------------------------------------------- the root

    /**
     * The absolute directory view names resolve against.
     *
     * Defaults to `<base>/views`. `config('app.views')` overrides it, either as
     * an absolute path or as one relative to the project root — relative is the
     * normal case, because an absolute path in a config file is a path that is
     * wrong on every other machine, and the repo is what gets shared.
     */
    public function root(): string
    {
        if ($this->root !== null) {
            return $this->root;
        }

        $configured = config('app.views');

        if (is_string($configured) && trim($configured) !== '') {
            // A Windows path or a rooted Unix one is already absolute. Testing
            // both separators because a path written in config on one platform
            // is routinely read on the other.
            $this->root = preg_match('#^(?:[A-Za-z]:[\\\\/]|[\\\\/])#', $configured) === 1
                ? rtrim($configured, '/\\')
                : base_path($configured);

            return $this->root;
        }

        $this->root = base_path('views');

        return $this->root;
    }

    /**
     * Point the renderer at a different directory.
     *
     * For a test that wants a fixture view without writing into the project's
     * view directory, and for a project rendering a second set of templates —
     * an email set, a PDF set — from one process.
     */
    public function setRoot(string $root): static
    {
        $this->root = rtrim($root, '/\\');

        return $this;
    }

    // ----------------------------------------------------------- shared and flash

    /**
     * Make a value available to every view.
     *
     * Returns `$this` so it composes with resolution:
     * `app(ViewRenderer::class)->share('appName', config('app.name'))`.
     */
    public function share(string $key, mixed $value): static
    {
        $this->shared[$key] = $value;

        return $this;
    }

    /**
     * Make several values available to every view.
     *
     * @param array<string, mixed> $values
     */
    public function shareMany(array $values): static
    {
        foreach ($values as $key => $value) {
            $this->share((string) $key, $value);
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function shared(): array
    {
        return $this->shared;
    }

    /**
     * The per-field validation errors the view should render inline.
     *
     * ## Why this reads a transient rather than a variable
     *
     * The errors arrive with the request that lands *after* a redirect, and that
     * request's action never saw them — they were flashed by the action that
     * rejected the form, in the previous request. Threading them through every
     * action's signature would mean every action forwarding a payload it does not
     * use, and forgetting once produces a form that silently stops showing errors.
     * So the renderer reads them from the flash store directly.
     *
     * ## Why the read is idempotent, when reading flash usually is not
     *
     * {@see RedirectResponse::readFlash()} consumes, and rightly: a *message* that
     * could be read twice reappears on refresh. Errors are different — one action
     * may render a header and a form, both of which want them, and a partial
     * rendered before the errors were read would come out empty. So the read
     * happens **once per renderer instance** and the result is cached; the
     * transient is still consumed by that first read, so a refresh does not
     * resurrect the errors either.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $flash = $this->flash();

        $errors = $flash['errors'] ?? [];

        if (!is_array($errors) || $errors === []) {
            return [];
        }

        $normalized = [];

        foreach ($errors as $field => $messages) {
            if (is_string($field)) {
                $normalized[$field] = $this->toList($messages);
            }
        }

        return $normalized;
    }

    /**
     * One field's messages, for an inline `<span>` beside the input.
     *
     * @return list<string>
     */
    public function errorsFor(string $field): array
    {
        return $this->errors()[$field] ?? [];
    }

    /**
     * The first message for one field, or null.
     *
     * What an input's error slot almost always wants: showing all three things
     * wrong with one box is noise.
     */
    public function firstError(string $field): ?string
    {
        return $this->errorsFor($field)[0] ?? null;
    }

    /**
     * Whether the given field failed validation.
     *
     * The hook a view uses to add `class="field-invalid"` without repeating the
     * error text, and the reason this is a method rather than leaving the view to
     * call `errorsFor()` and test it — an empty list and a missing field are the
     * same answer, and a view should not have to know that.
     */
    public function hasError(string $field): bool
    {
        return $this->errorsFor($field) !== [];
    }

    /**
     * The input the user submitted, for re-populating the form.
     *
     * Flashed by the same failed validation that produced {@see errors()}, and
     * read from the same cache — see the note there on why one read serves both.
     * Values are returned **raw**; escaping them is the view's job, through
     * {@see old()} or {@see e()}.
     *
     * @return array<string, mixed>
     */
    public function old(): array
    {
        $old = $this->flash()['old'] ?? [];

        return is_array($old) ? $old : [];
    }

    /**
     * One submitted value, or a default.
     *
     * @param mixed $default
     * @return mixed
     */
    public function oldValue(string $field, mixed $default = null): mixed
    {
        return $this->old()[$field] ?? $default;
    }

    /**
     * Every flash value the previous request left, consumed.
     *
     * Cached per instance for the reason given on {@see errors()}: the transient
     * is destroyed by the read, so a second uncached read would come back empty
     * and a page that rendered a notice in two places would render it once.
     *
     * @return array<string, mixed>
     */
    public function flash(): array
    {
        if ($this->flash === null) {
            $this->flash = $this->loadFlash();
        }

        return $this->flash;
    }

    /**
     * One flash value, or a default.
     *
     * The general form of what a "saved successfully" notice reads:
     * `$view->flash('notice')`.
     *
     * @param mixed $default
     * @return mixed
     */
    public function flashValue(string $key, mixed $default = null): mixed
    {
        return $this->flash()[$key] ?? $default;
    }

    // ------------------------------------------------------------------ internals

    /**
     * The cached flash payload, or null before it has been read.
     *
     * @var array<string, mixed>|null
     */
    private ?array $flash = null;

    /**
     * Build the full variable set for one render.
     *
     * Layered explicitly rather than with `array_merge` of everything at once, so
     * the precedence is readable at the call site — this is the order in which a
     * value is overridden, and getting it backwards means an action cannot
     * override a shared value or a shared value silently beats a flashed one.
     *
     * The flash block is **absent** when nothing was flashed, rather than present
     * as empty arrays. A view that checks `isset($errors)` then behaves the same
     * as one that checks `$errors !== []`, and neither has to guess what an
     * empty payload means.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function gather(array $data): array
    {
        $variables = $this->shared;

        $flash = $this->flash();

        $errors = $this->errors();

        if ($errors !== []) {
            $variables['errors'] = $errors;
        }

        $old = $this->old();

        if ($old !== []) {
            $variables['old'] = $old;
        }

        if (array_key_exists('error', $flash)) {
            $variables['error'] = $flash['error'];
        }

        if (array_key_exists('notice', $flash)) {
            $variables['notice'] = $flash['notice'];
        }

        // $data last: an action that names a variable means it, and the renderer
        // is not in a position to know better than the code that wrote the view.
        return array_replace($variables, $data);
    }

    /**
     * Read the flash payload from WordPress, or from a request-bound fallback.
     *
     * @return array<string, mixed>
     */
    private function loadFlash(): array
    {
        // The only source. An earlier draft also consulted the request, to help a
        // test avoid two round trips — and it was removed, because it made the
        // flash *two* stores with a silent precedence rule between them, and a
        // second store that can shadow the first is how a "the errors stopped
        // showing up" bug becomes unguessable. A test writes to the transient
        // instead; see Tests\Support\FlashStore.
        return RedirectResponse::readFlash();
    }

    /**
     * Whether a view name tries to climb out of the view root.
     *
     * ## Why this is a substring test and not a segment split
     *
     * The obvious implementation — split on separators and look for a `'..'`
     * segment — does not work here, and fails in the *unsafe* direction. Dots are
     * the delimiter this renderer substitutes, so `..` is itself a run of
     * separators: splitting `'../secret'` on `[./]+` yields `['', 'secret']`, and
     * the parent segment has already vanished by the time anything could look for
     * it. A guard written that way compiles, runs, and never fires once.
     *
     * So the check is on the raw text, before anything is substituted. Any `..`
     * is refused, including in a name like `leads..index` where it is harmless —
     * a view file with two consecutive dots in its name is not a thing anyone
     * writes, and the cost of refusing one is a clearer error than the cost of
     * letting an escape through is a file read.
     */
    private function escapesRoot(string $view): bool
    {
        return str_contains($view, '..');
    }

    /**
     * Turn a relative view path into the slash form used for lookup.
     *
     * Collapses the doubled separators the dot substitution can produce, and
     * strips leading ones, so `'.leads.index'` and `'leads.index'` agree.
     */
    private function normalize(string $relative): string
    {
        $segments = array_values(array_filter(
            explode('/', str_replace('.', '/', $relative)),
            static fn (string $segment): bool => $segment !== ''
        ));

        return implode('/', $segments);
    }

    /**
     * Coerce a message, or a list of them, into a list.
     *
     * A flashed payload may carry a single string where a list is expected — a
     * hand-written `->with('errors', ['email' => 'Required'])` does exactly that,
     * and it is a natural thing for a project to write. Normalizing here means a
     * view's loop does not have to guard, which is the difference between one
     * line of defensive code and one in every template.
     *
     * @return list<string>
     */
    private function toList(mixed $messages): array
    {
        if (is_string($messages)) {
            return [$messages];
        }

        if (!is_array($messages)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $message): string => is_scalar($message) ? (string) $message : '',
            array_filter($messages, static fn (mixed $message): bool => is_scalar($message))
        ));
    }
}
