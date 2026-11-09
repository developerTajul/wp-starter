<?php
declare(strict_types=1);

namespace Framework\Console\Commands;

use Framework\Config\Config;
use Framework\Console\GeneratorCommand;
use RuntimeException;

/**
 * `make:controller` - scaffold a controller in one of four shapes.
 *
 * The shape is chosen by flag, exactly as Laravel spells them:
 *
 *     wp wp_mini make:controller CompanyController               bare, no actions
 *     wp wp_mini make:controller CompanyController --resource    seven web actions
 *     wp wp_mini make:controller CompanyController --api         five REST actions
 *     wp wp_mini make:controller CompanyController --invokable   a single __invoke()
 *
 * The flag picks the stub, and the flag is the only thing that does
 *
 * `--resource` is a **web** controller: it renders views, redirects form posts,
 * and never sees a `WP_REST_Request`. `--api` is a **REST** controller: it
 * returns a `WP_REST_Response` and WordPress's REST server renders it.
 *
 * Those two are not the same file with different names in it - they do not share
 * a base class, a request type or a return type - so they are two different
 * stubs. `--api` and `--invokable` (REST) extend `Framework\Routing\Controller`
 * and take a `WP_REST_Request`; `--resource` and a bare `--invokable` are web
 * controllers with no base class.
 *
 * The flags are therefore mutually exclusive rather than composable: asking for
 * two shapes at once is ambiguous, and it is refused rather than resolved.
 *
 * The web resource stub reads like Laravel
 *
 * A generated `--resource` controller is a `final` class with no constructor:
 * the four screens call the global `view()` helper, and the three form handlers
 * answer with `to_route(...)->with('success', ...)`. Nothing is injected and
 * nothing is stubbed, because a stub is a starting point - every placeholder body
 * is a line the developer is about to replace with the feature's own logic, and
 * an injected dependency is one more thing to delete first.
 *
 * Both helpers resolve through the container at call time ({@see view()} builds
 * the `ViewRenderer`, {@see to_route()} resolves the named route), which is what
 * lets a scaffold with no constructor stay honest: the response the action
 * returns is the same one an injected renderer would have produced.
 *
 * Why the shape is a flag and not a second command
 *
 * The shapes produce one class in one directory from one naming convention, and
 * differ only in *which stub* is rendered. A `make:resource-controller` command
 * would duplicate the namespace, the target path, the sub-directory handling and
 * the overwrite guard to change one line - and the two commands would drift the
 * first time one of them was fixed.
 *
 * Why the flags are read here and not in the base class
 *
 * Every generator receives `$assocArgs` - it is part of `Command::handle()`'s
 * contract - but only this one has flags that change what it generates. Threading
 * them through the base class would put an argument three of the five generators
 * have no use for into the signature they all implement, to say something only
 * this one asks. The base class stays as it is; the flag is read where it means
 * something.
 *
 * The flags are mutually exclusive, and a conflict is refused
 *
 * `--resource --api` names two different file shapes. Picking one silently
 * would generate a controller that does not match what was asked for, and the
 * report would say it succeeded - so it is reported instead. The refusal happens
 * before anything is written, so a rejected run leaves no file behind.
 */
class MakeControllerCommand extends GeneratorCommand
{
    /**
     * The stub rendered when no flag is given: a bare controller.
     *
     * No actions at all, which is what `make:controller Foo` produces in Laravel
     * too. The three scaffolded REST actions this used to emit were the one thing
     * in the file nobody kept - the first thing a developer does is delete them
     * and write the actions the resource actually has, and until they do, the
     * routes they declare are live and wrong.
     *
     * It also collapses a duplicate. An earlier revision shipped this and
     * {@see STUB_API} as two files differing only in action count, which
     * is two files to keep in step and no reason for the second to exist.
     */
    private const STUB_DEFAULT = 'controller.stub';

    /**
     * The REST stub rendered for `--api`.
     *
     * Five actions - `index`, `store`, `show`, `update`, `destroy` - which is the
     * RESTful set for a resource that has no separate create/edit *forms*. Those
     * two are web-only screens, so an API controller has no use for them.
     */
    private const STUB_API = 'controller.api.stub';

    /**
     * The web stub rendered for `--resource`.
     *
     * Seven actions, the two the API stub omits being `create` and `edit` - the
     * screens that render a form. `store` and `update` handle the submits those
     * forms post to, which is why a web resource has both halves and a REST one
     * has only the handlers.
     */
    private const STUB_WEB_RESOURCE = 'controller.resource.stub';

    /**
     * The REST stub rendered for `--invokable`.
     *
     * A different file from {@see STUB_WEB_INVOKABLE}, not the same one reached
     * by two names: the two surfaces share no base class, no request type and no
     * return type, so an invokable controller mounted by the REST server and one
     * mounted as a web screen cannot be one file with a namespace changed.
     */
    private const STUB_INVOKABLE = 'controller.api.invokable.stub';

    /**
     * The web stub rendered for `--invokable`.
     */
    private const STUB_WEB_INVOKABLE = 'controller.invokable.stub';

    /**
     * The sub-namespace segment that marks a controller as web-facing.
     *
     * Matched against the *first* segment, case-sensitively, because that is the
     * segment the caller had to spell as a namespace (`ClassName::parse()`
     * enforces upper-case). `Admin/Reports/CompanyController` is an admin
     * controller; `Reports/CompanyController` is not, because there is no rule
     * that says a namespace called `Reports` is a screen.
     *
     * Read only by `--invokable`, which is the one shape with no surface flag of
     * its own - see {@see resolveShape()}.
     */
    private const ADMIN_SEGMENT = 'Admin';

    /**
     * The stub this run resolved, held so the hint and the stub agree.
     *
     * Set by {@see getStubPath()}, read by {@see getPostWriteHint()}. PHP calls
     * the first before the second - `render()` resolves the stub, and only then
     * does `handle()` ask for the hint - so the property is always populated by
     * the time it is read. Held rather than recomputed for the same reason
     * {@see \Framework\Database\Commands\MakeMigrationCommand} holds its
     * timestamp: one run, one decision, two readers, which is what stops the hint
     * describing a different shape than the file that was written.
     *
     * There is no third state to represent, because the flags that would create
     * one are refused rather than resolved - see {@see getStubPath()}.
     */
    private string $shape = self::STUB_DEFAULT;

    public function getName(): string
    {
        return config('app.cli_prefix', Config::DEFAULT_CLI_PREFIX) . ' make:controller';
    }

    public function getDescription(): string
    {
        return 'Create a new controller class (--resource, --api, --invokable).';
    }

    protected function getType(): string
    {
        return 'Controller';
    }

    protected function getNamespace(): string
    {
        return config('app.namespace', Config::DEFAULT_NAMESPACE) . '\\Http\\Controllers';
    }

    /**
     * The stub the current run renders - the only thing the flags change.
     *
     * A bare name, not a path: StubLocator checks the project's own `stubs/`
     * first and falls back to the framework's, which is what keeps this working
     * when the framework is installed under `vendor/`. A project that wants a
     * different resource controller copies `controller.resource.stub` into its
     * own `stubs/` directory and edits that.
     *
     * @throws RuntimeException When more than one shape flag was given.
     */
    protected function getStubPath(): string
    {
        $shape = $this->resolveShape();

        $this->shape = $shape;

        return $shape;
    }

    /**
     * Read the three shape flags and refuse a request for more than one.
     *
     * Why this is a count and not a match
     *
     * Three flags name three different files, and any two of them together name
     * a file that does not exist. Counting the requests and rejecting a count
     * above one is what keeps the refusal total: a `match` over the pair would
     * have to enumerate every combination, and the one it forgot would fall
     * through to the default stub - a controller of the wrong shape reported as
     * success.
     *
     * The refusal is thrown, not returned, and thrown before the target path is
     * computed, so a rejected run leaves nothing on disk. The base class catches
     * a RuntimeException from anywhere in its `handle()` and reports it as the
     * command's own error.
     *
     * @throws RuntimeException When more than one shape flag was given.
     */
    private function resolveShape(): string
    {
        $wanted = [];

        if ($this->hasFlag('resource')) {
            $wanted['--resource'] = self::STUB_WEB_RESOURCE;
        }

        if ($this->hasFlag('api')) {
            $wanted['--api'] = self::STUB_API;
        }

        if ($this->hasFlag('invokable')) {
            // The invokable stub depends on the surface, and with no surface flag
            // left to read it comes from the namespace: `Admin/…` is a screen and
            // anything else is REST. That is the one place the namespace still
            // decides anything, and it is deliberate - there is no `--web` flag
            // for `--invokable` to pair with, and inventing one would be a second
            // way to say what the path already says.
            $wanted['--invokable'] = $this->isWebSurface()
                ? self::STUB_WEB_INVOKABLE
                : self::STUB_INVOKABLE;
        }

        if (count($wanted) > 1) {
            throw new RuntimeException(sprintf(
                '%s ask for %d different controllers. Pass one.',
                implode(' and ', array_keys($wanted)),
                count($wanted)
            ));
        }

        return $wanted === [] ? self::STUB_DEFAULT : reset($wanted);
    }

    /**
     * Whether this run generates into the web surface.
     *
     * The first sub-namespace segment only - {@see ADMIN_SEGMENT} - because the
     * choice is about the top-level namespace the caller typed, not about
     * anything nested beneath it. Read only by `--invokable`, which has no
     * surface flag of its own; `--resource` and `--api` name their surface
     * directly.
     */
    private function isWebSurface(): bool
    {
        $sub = $this->subNamespace();

        if ($sub === '') {
            return false;
        }

        return str_starts_with($sub, self::ADMIN_SEGMENT)
            && ($sub === self::ADMIN_SEGMENT || $sub[strlen(self::ADMIN_SEGMENT)] === '\\');
    }

    protected function getTargetPath(string $className): string
    {
        // `Admin/CompanyController` lands in app/Http/Controllers/Admin/, with the
        // matching namespace appended by the base class. See subDirectoryPath().
        return app_path('Http/Controllers/' . $this->subDirectoryPath() . $className . '.php');
    }

    /**
     * @return array<string, string>
     */
    protected function getReplacements(string $className): array
    {
        $base = $this->baseName($className);

        return [
            // The path the REST stub's #[Route] attributes point at. Derived from
            // the *class name*, not from the argument the caller typed, so
            // `Admin/CompanyController` routes at `Company` rather than at a path
            // nested under a word that is a directory name - the base class has
            // already stripped the sub-namespace and the `.php` suffix by the time
            // this is called.
            //
            // Upper-cased, because a REST path segment is a noun in a URL
            // (`/Company/42`) and the routes this framework ships spell it that
            // way. The *name* below is lower-cased instead: the two are different
            // namespaces and are allowed to disagree about case, and each is
            // spelled the way its own surface conventionally spells it.
            '{{ route }}' => $base,
            // The route *name* prefix the web stub hands to `to_route()`, so
            // `CompanyController` redirects to `company.index`. Lower-cased and
            // singular to match the names in `routes/web.php` (`leads.index`,
            // `leads.create`) - the name the developer will write on the route
            // itself, since a name that does not match is a redirect that throws
            // at run time rather than at generation.
            '{{ name }}' => lcfirst($base),
            // The view directory and the two data keys the web stubs hand to it.
            // Derived from the same base name so a generated controller and the
            // views it expects cannot disagree about what the feature is called.
            '{{ view }}' => $this->viewPath($base),
            '{{ items }}' => $this->plural($base),
            '{{ item }}' => lcfirst($base),
        ];
    }

    /**
     * What to tell the caller once the file is on disk.
     *
     * Branches on the *stub* rather than on the flag, because the flag no longer
     * determines the surface on its own - `--resource` under `Admin/` is a web
     * controller and `--resource` anywhere else is a REST one, and a hint naming
     * the wrong mount is the one piece of guidance a generated file cannot
     * correct for itself.
     */
    protected function getPostWriteHint(): ?string
    {
        return match ($this->shape) {
            self::STUB_API => 'Mount it with Route::controller(' . $this->parsed->class
                . '::class, [...], \'' . $this->routePath($this->parsed->class) . '\') in routes/api.php.',
            self::STUB_INVOKABLE => 'Mount it with Route::post(\'' . $this->routePath($this->parsed->class)
                . '\', ' . $this->parsed->class . '::class) in routes/api.php.',
            self::STUB_WEB_RESOURCE => 'Mount it with Route::adminPage(...) / Route::adminAction(...) in routes/web.php.',
            self::STUB_WEB_INVOKABLE => 'Mount it with Route::adminPage(\'…\', ' . $this->parsed->class
                . '::class) in routes/web.php.',
            default => 'It declares no routes yet; add #[Route] attributes and mount it in routes/api.php.',
        };
    }

    /**
     * The path the generated controller's routes are mounted under.
     *
     * The class name with a trailing `Controller` suffix removed, so
     * `CompanyController` routes at `Company` rather than at `CompanyController`.
     * The suffix is stripped only when something is left - a class literally
     * named `Controller` keeps its name rather than routing at the empty path,
     * which would mount it at the REST namespace root and collide with every
     * sibling.
     *
     * The spelling is left exactly as the class spelled it (`CompanyController`
     * → `Company`): lower-casing or pluralizing here would be guessing at a
     * convention the project has not stated, and a wrong guess is a route the
     * developer then has to hunt for. It is a starting point to edit, not a
     * promise.
     */
    private function baseName(string $className): string
    {
        return str_ends_with($className, 'Controller') && $className !== 'Controller'
            ? substr($className, 0, -strlen('Controller'))
            : $className;
    }

    private function routePath(string $className): string
    {
        return $this->baseName($className);
    }

    /**
     * The view directory a web controller renders from.
     *
     * Lower-cased, so `CompanyController` looks for `views/company/` - the
     * convention WordPress and Laravel templates both follow, and the reason the
     * generated controller's view names are lower-case while its route path is
     * not. A view is a file path and a route is a URL; they are allowed to
     * disagree about case, and this is where they do.
     *
     * Why the sub-namespace is dropped
     *
     * `Admin/CompanyController` renders `company.index`, not `admin.company.index`.
     * The `Admin/` segment places the file and names its namespace; it is not part
     * of the feature's name, and a view directory per admin section would mean
     * every screen under `Admin/` nests one level deeper than the views that ship
     * with the framework - `views/leads/index.php`, not
     * `views/admin/leads/index.php`.
     */
    private function viewPath(string $base): string
    {
        return strtolower($base);
    }

    /**
     * The naive plural of a word, for the collection key in a web index view.
     *
     * Deliberately not a real inflector. This names a **local variable** in a
     * generated action - `$companies` - that the developer renames the moment
     * they write the query behind it, and an inflector that got `person` → `people`
     * right would still be wrong about `lead` → `leads` versus a project that
     * says `lead_items`. The rules below cover the endings that actually appear in
     * class names; everything else falls through to `+ s`.
     */
    private function plural(string $word): string
    {
        // Lower-cased first, so the collection key matches the singular beside it
        // - `'companies' => []` and `'company' => null` in the same stub. Applying
        // the rule to the StudlyCase base would emit `'Companies'`, which is a
        // key no view variable ever uses and is inconsistent with its own
        // singular.
        $word = lcfirst($word);
        $lower = strtolower($word);

        return match (true) {
            str_ends_with($lower, 'y') && !str_ends_with($lower, 'ay')
                && !str_ends_with($lower, 'ey') && !str_ends_with($lower, 'oy') => substr($word, 0, -1) . 'ies',
            str_ends_with($lower, 's'), str_ends_with($lower, 'x'),
            str_ends_with($lower, 'z'), str_ends_with($lower, 'ch'),
            str_ends_with($lower, 'sh') => $word . 'es',
            default => $word . 's',
        };
    }
}
