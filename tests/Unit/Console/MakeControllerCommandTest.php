<?php
declare(strict_types=1);

namespace Tests\Unit\Console;

use DevLinkStudios\WPServiceContainer\Container;
use Framework\Config\Config;
use Framework\Console\Commands\MakeControllerCommand;
use Framework\Routing\Controller;
use Framework\Routing\NamedRoutes;
use Framework\Routing\Route;
use Framework\Routing\RouteResolver;
use Framework\Routing\Router;
use PHPUnit\Framework\TestCase;
use Tests\Support\WpStub;

/**
 * `make:controller`, and specifically the nested form Laravel users reach for:
 * `make:controller Admin/CompanyController`.
 *
 * ## What is actually being pinned
 *
 * Not "a file appears". The failure mode this covers produces a file that
 * appears, parses, and is reported as created — and is still wrong. Two ways:
 *
 * 1. The name is flattened, so `Admin/CompanyController` writes
 *    `AdminCompanyController.php` into the root folder. This is what the command
 *    did before, and it is visible in the working tree it left behind: both
 *    `app/Http/Controllers/AdminCompanyController.php` and `CompanyController.php`
 *    exist, one directory level too high.
 * 2. The *namespace* and the *directory* disagree. A generator that appends the
 *    sub-namespace in one place and not the other writes
 *    `Admin/CompanyController.php` declaring `App\Http\Controllers` — a class
 *    Composer cannot find, failing much later and somewhere else entirely.
 *
 * Both are covered below, and the second is covered by resolving the generated
 * file through Composer's own PSR-4 map rather than by comparing strings.
 */
final class MakeControllerCommandTest extends TestCase
{
    /**
     * The flag spelling WP-CLI's own dispatch produces — dashes stripped.
     *
     * Named constants rather than scattered literals so the tests that exercise
     * the *other* spellings stand out as deliberate rather than as typos.
     */
    private const FLAG_RESOURCE = 'resource';
    private const FLAG_API = 'api';
    private const FLAG_INVOKABLE = 'invokable';

    private string $workspace;
    private ?string $originalBase = null;

    protected function setUp(): void
    {
        WpStub::reset();

        $this->workspace = sys_get_temp_dir() . '/wpml-makectl-' . bin2hex(random_bytes(6));

        mkdir($this->workspace . '/app/Http/Controllers', 0777, true);

        // The generator resolves its *stub* through StubLocator, which looks in
        // the project first — and the project is `base_path()`. The scratch tree
        // deliberately has no `stubs/` directory, so this also pins the fallback
        // to the framework's own copy: if resolution regressed to a required
        // project stub, every case below would fail with "Stub file not found".
        $this->originalBase = app()->bound('path.base') ? (string) app('path.base') : null;
        app()->instance('path.base', $this->workspace);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);

        if ($this->originalBase !== null) {
            app()->instance('path.base', $this->originalBase);
        } else {
            app()->forget('path.base');
        }
    }

    // -------------------------------------------------------- the nested case

    /**
     * The headline behaviour: a slash makes a sub-directory, and the class is
     * named only for its last segment.
     */
    public function test_a_slashed_name_creates_the_nested_directory(): void
    {
        $command = $this->command();
        $command->handle(['Admin/CompanyController'], []);

        $this->assertDirectoryExists($this->workspace . '/app/Http/Controllers/Admin');
        $this->assertFileExists($this->workspace . '/app/Http/Controllers/Admin/CompanyController.php');

        // The flattened spelling must not be produced as well — a generator that
        // wrote both would pass a "the file exists" check twice over.
        $this->assertFileDoesNotExist($this->workspace . '/app/Http/Controllers/AdminCompanyController.php');
    }

    /**
     * The namespace gains the sub-namespace, and the file name does not.
     */
    public function test_the_namespace_contains_the_sub_namespace(): void
    {
        $this->command()->handle(['Admin/CompanyController'], []);

        $contents = $this->read('Admin/CompanyController.php');

        $this->assertStringContainsString('namespace App\Http\Controllers\Admin;', $contents);
        $this->assertStringContainsString('class CompanyController', $contents);

        // The stub's own placeholders must be gone; a surviving `{{ namespace }}`
        // would be a syntax error, and a surviving `{{ class }}` would be a
        // literal in the route path below.
        $this->assertDoesNotMatchRegularExpression('/\{\{\s*\w+\s*\}\}/', $contents);
    }

    /**
     * The generated class must be the class its namespace promises.
     *
     * Read through the project's real PSR-4 map rather than compared against a
     * literal: the map is what decides autoloading, so asserting against it is
     * asserting the thing that actually matters. A namespace that is *spelled*
     * the way this test expects but is not what the map resolves will still fail
     * here, which is the point.
     */
    public function test_the_generated_class_is_autoloadable_under_the_projects_psr4_map(): void
    {
        $this->command()->handle(['Admin/CompanyController'], []);

        $map = $this->psr4PrefixForApp();

        // The prefix the test's own config implies, e.g. `App\` — read from
        // config rather than hardcoded, so a rebranded project runs this too.
        $prefix = (string) config('app.namespace', Config::DEFAULT_NAMESPACE) . '\\';

        $this->assertArrayHasKey(
            $prefix,
            $map,
            "composer.json has no PSR-4 map for [{$prefix}]; the generated namespace cannot autoload."
        );

        $expected = $map[$prefix] . 'Http/Controllers/Admin/CompanyController.php';

        $this->assertFileExists(
            $this->workspace . '/' . ltrim($expected, '/'),
            "PSR-4 resolves [{$prefix}Http\\Controllers\\Admin\\CompanyController] to [{$expected}], which does not exist."
        );
    }

    /**
     * The route path the stub writes is the class name, not the input — a stub
     * that substituted the raw argument would produce
     * `#[Route('GET', 'Admin/CompanyController')]`, a path nested under a word
     * that is a directory name rather than part of the route.
     */
    public function test_the_stub_route_paths_use_the_class_name_not_the_argument(): void
    {
        $this->command()->handle(['Api/CompanyController'], [self::FLAG_API => true]);

        $contents = $this->read('Api/CompanyController.php');

        // The route path is the class name with the suffix stripped — `Company` —
        // never the argument the caller typed.
        $this->assertStringContainsString("'Company'", $contents);
        $this->assertStringNotContainsString('Api/Company', $contents);
        $this->assertStringNotContainsString('ApiCompanyController', $contents);
    }

    /**
     * Depth is not limited to one level, and both separators are accepted.
     */
    public function test_it_handles_more_than_one_level(): void
    {
        $this->command()->handle(['Admin/Leads/CompanyController'], []);

        $contents = $this->read('Admin/Leads/CompanyController.php');

        $this->assertStringContainsString('namespace App\Http\Controllers\Admin\Leads;', $contents);
    }

    public function test_a_backslash_separated_name_is_the_same_request(): void
    {
        $this->command()->handle(['Admin\\CompanyController'], []);

        $this->assertFileExists($this->workspace . '/app/Http/Controllers/Admin/CompanyController.php');
    }

    // ------------------------------------------------------- the flat case

    /**
     * The un-nested form must keep working exactly as before — no trailing
     * separator, no doubled slash, no namespace tail.
     */
    public function test_a_bare_name_still_lands_in_the_controllers_root(): void
    {
        $this->command()->handle(['CompanyController'], []);

        $contents = $this->read('CompanyController.php');

        $this->assertFileExists($this->workspace . '/app/Http/Controllers/CompanyController.php');
        $this->assertStringContainsString('namespace App\Http\Controllers;', $contents);
        $this->assertStringNotContainsString('App\Http\Controllers\\;', $contents);
    }

    /**
     * A `.php` suffix on a nested name is noise and is dropped, while the
     * directory it was attached to is kept.
     */
    public function test_a_php_suffix_is_stripped_without_losing_the_directory(): void
    {
        $this->command()->handle(['Admin/CompanyController.php'], []);

        $this->assertFileExists($this->workspace . '/app/Http/Controllers/Admin/CompanyController.php');
    }

    // ----------------------------------------------------------- the refusals

    /**
     * A lower-case first segment is refused rather than scaffolded.
     *
     * `admin/CompanyController` is the one input where the caller's meaning is
     * genuinely ambiguous — `admin` reads as a namespace and as a class name
     * equally well — so it is named in the error rather than quietly resolved
     * into a namespace no other file in the project spells that way.
     */
    public function test_a_lower_case_sub_namespace_is_refused_with_a_reason(): void
    {
        $command = $this->command();
        $command->handle(['admin/CompanyController'], []);

        $this->assertStringContainsString('upper-case', implode("\n", $command->messages));
        $this->assertFileDoesNotExist($this->workspace . '/app/Http/Controllers/admin/CompanyController.php');
        $this->assertFileDoesNotExist($this->workspace . '/app/Http/Controllers/adminCompanyController.php');
    }

    /**
     * A segment that is not a label at all is refused, and nothing is written.
     */
    public function test_an_illegal_segment_is_refused(): void
    {
        $command = $this->command();
        $command->handle(['Admin/9-Bad'], []);

        $this->assertStringContainsString('Invalid controller name', implode("\n", $command->messages));
        $this->assertFileDoesNotExist($this->workspace . '/app/Http/Controllers/9Bad.php');
    }

    /**
     * The overwrite guard applies to nested paths too — it is keyed on the
     * resolved path, so a nested file must be protected like any other.
     */
    public function test_it_refuses_to_overwrite_an_existing_nested_controller(): void
    {
        mkdir($this->workspace . '/app/Http/Controllers/Admin', 0777, true);
        file_put_contents($this->workspace . '/app/Http/Controllers/Admin/CompanyController.php', 'hand written');

        $command = $this->command();
        $command->handle(['Admin/CompanyController'], []);

        $this->assertSame('hand written', $this->read('Admin/CompanyController.php'));
        $this->assertStringContainsString('already exists', implode("\n", $command->messages));
    }

    /**
     * The success message names the real path, so the operator can see the
     * directory was created rather than inferring it from silence.
     */
    public function test_the_success_message_names_the_nested_path(): void
    {
        $command = $this->command();
        $command->handle(['Admin/CompanyController'], []);

        $this->assertStringContainsString(
            'Http/Controllers/Admin/CompanyController.php',
            implode("\n", $command->messages)
        );
    }

    // ----------------------------------------------------------- the shapes

    /**
     * With no flag, the command generates a bare controller and nothing else.
     *
     * The default stub declares **no actions at all** — `make:controller Foo`
     * produces the same in Laravel. The three scaffolded REST actions this used
     * to emit were the one thing in the file nobody kept: the first thing a
     * developer does is delete them and write the actions the resource actually
     * has, and until they do, the routes they declare are live and wrong.
     *
     * So the assertion is an absence, and it is the assertion that matters — a
     * stub that went back to emitting actions would ship working-looking routes
     * for every bare `make:controller` in the wild.
     */
    public function test_no_flag_generates_a_bare_controller(): void
    {
        $this->command()->handle(['CompanyController'], []);

        $contents = $this->read('CompanyController.php');

        $this->assertStringContainsString('class CompanyController extends Controller', $contents);

        foreach (['index', 'create', 'store', 'show', 'edit', 'update', 'destroy', '__invoke'] as $action) {
            $this->assertStringNotContainsString(
                'public function ' . $action . '(',
                $contents,
                "A bare controller must declare no actions; [{$action}] was found."
            );
        }
    }

    /**
     * `--resource` populates all seven conventional web actions.
     *
     * Asserted as *seven specific method names* rather than a count: a stub that
     * emitted `index` seven times would satisfy a count, and the conventional
     * names are the whole point of the flag.
     *
     * Seven, not five, because `--resource` is the *web* shape: it carries
     * `create` and `edit` as well, the two screens that render a form. The REST
     * shape drops those two and is reached with `--api` — see
     * {@see test_the_api_flag_generates_all_five_rest_actions()}.
     */
    public function test_the_resource_flag_generates_all_seven_actions(): void
    {
        $this->command()->handle(['CompanyController'], [self::FLAG_RESOURCE => true]);

        $contents = $this->read('CompanyController.php');

        foreach (['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'] as $action) {
            $this->assertMatchesRegularExpression(
                '/public function ' . $action . '\(/',
                $contents,
                "The --resource stub must declare a [{$action}] action."
            );
        }

        $this->assertStringNotContainsString('__invoke', $contents);
    }

    /**
     * `--api` populates the five REST actions and only those.
     *
     * The complement of the seven-action test above, and asserted the same way:
     * the five by name, and the two web-only screens by *absence*. A stub that
     * kept `create`/`edit` would mount them as REST routes — endpoints that
     * render a form to a REST client that cannot see one.
     */
    public function test_the_api_flag_generates_all_five_rest_actions(): void
    {
        $this->command()->handle(['CompanyController'], [self::FLAG_API => true]);

        $contents = $this->read('CompanyController.php');

        foreach (['index', 'store', 'show', 'update', 'destroy'] as $action) {
            $this->assertMatchesRegularExpression(
                '/public function ' . $action . '\(/',
                $contents,
                "The --api stub must declare a [{$action}] action."
            );
        }

        foreach (['create', 'edit', '__invoke'] as $absent) {
            $this->assertStringNotContainsString(
                'public function ' . $absent . '(',
                $contents,
                "The --api stub must not declare [{$absent}]; it is a web-only screen."
            );
        }
    }

    /**
     * The REST stub returns REST responses and extends the REST base.
     *
     * The whole point of the split: an `--api` controller is mounted on
     * `routes/api.php` and answered by WordPress's REST server, so it returns
     * `WP_REST_Response` and takes the WordPress request.
     */
    public function test_the_rest_resource_stub_is_a_rest_controller(): void
    {
        $this->command()->handle(['CompanyController'], [self::FLAG_API => true]);

        $contents = $this->read('CompanyController.php');

        $this->assertStringContainsString('use WP_REST_Request;', $contents);
        $this->assertStringContainsString('WP_REST_Response', $contents);
        $this->assertStringContainsString('extends Controller', $contents);
        $this->assertStringNotContainsString('ViewRenderer', $contents);
    }

    /**
     * The REST resource stub's `#[Route]` attributes map the actions onto the
     * conventional verbs and paths.
     *
     * The collection pair is the one worth pinning: `index` and `store` share a
     * path and differ only by verb, so a stub that got the verb wrong would still
     * *look* complete and would fail only when a request arrived.
     */
    public function test_the_rest_resource_routes_use_the_conventional_verbs(): void
    {
        $this->command()->handle(['CompanyController'], [self::FLAG_API => true]);

        $contents = $this->read('CompanyController.php');

        $this->assertStringContainsString("#[Route('GET', 'Company')]", $contents);
        $this->assertStringContainsString("#[Route('POST', 'Company')]", $contents);
        $this->assertStringContainsString("#[Route('PUT', 'Company/(?P<id>", $contents);
        $this->assertStringContainsString("#[Route('DELETE', 'Company/(?P<id>", $contents);
    }

    // -------------------------------------------------------------- the web surface

    /**
     * An `Admin/` controller is generated as a *web* controller, not a REST one.
     *
     * ## Why this is the headline assertion of the split
     *
     * The failure it prevents is silent in both directions. A REST-shaped
     * controller under `Admin/` returns `WP_REST_Response`, which the web
     * dispatcher cannot render — the screen comes up blank with no error, because
     * the response object is simply never `send()`-ed. And it extends
     * `Framework\Routing\Controller`, whose helpers build exactly those
     * unrenderable objects.
     *
     * So the two things asserted are the two that decide whether the screen
     * renders: the *base class* (is it the REST one?) and the *return type*.
     */
    public function test_the_admin_resource_stub_is_a_web_controller(): void
    {
        $this->command()->handle(['Admin/CompanyController'], [self::FLAG_RESOURCE => true]);

        $contents = $this->read('Admin/CompanyController.php');

        $this->assertStringContainsString('use Framework\Http\Contracts\ResponseInterface;', $contents);
        $this->assertStringContainsString('ResponseInterface', $contents);

        // The marks of the REST shape, none of which may appear in a web
        // controller. Searched on the *code* rather than the whole file: the
        // stub's docblock explains what a REST controller would have looked like,
        // and naming the thing being avoided is not the same as emitting it.
        $code = $this->codeOnly($contents);

        $this->assertStringNotContainsString('WP_REST_Response', $code);
        $this->assertStringNotContainsString('WP_REST_Request', $code);
        $this->assertStringNotContainsString('Framework\Routing\Controller', $code);
        $this->assertStringNotContainsString('extends', $code);
        $this->assertStringNotContainsString('routes/api.php', $code);
    }

    /**
     * The file's code with comment lines removed.
     *
     * The web resource stub carries a substantial docblock — it explains the
     * screens/forms split, why a web controller extends nothing, and which service
     * and form requests the commented bodies expect. Prose *about* the REST
     * surface is not the REST surface, so assertions about what the file does not
     * contain have to read the code and leave the explanation alone.
     *
     * Line-based rather than a token scan, because the question is only ever
     * "does this line execute": `//` and `*` both start a comment in the shapes
     * these stubs write, and neither appears at the start of a line of real code.
     */
    private function codeOnly(string $contents): string
    {
        $code = [];

        foreach (explode("\n", $contents) as $line) {
            $trimmed = ltrim($line);

            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '/*')) {
                continue;
            }

            $code[] = $line;
        }

        return implode("\n", $code);
    }

    /**
     * The admin resource controller still declares the seven conventional actions.
     *
     * The surface changes what each action *returns*; it does not change which
     * actions a resource controller has. A web controller missing `create` or
     * `edit` — the two that only exist on the web side — would be the wrong file.
     */
    public function test_the_admin_resource_stub_declares_all_seven_actions(): void
    {
        $this->command()->handle(['Admin/CompanyController'], [self::FLAG_RESOURCE => true]);

        $contents = $this->read('Admin/CompanyController.php');

        foreach (['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'] as $action) {
            $this->assertMatchesRegularExpression(
                '/public function ' . $action . '\(/',
                $contents,
                "The web resource stub must declare a [{$action}] action."
            );
        }
    }

    /**
     * The web form handlers redirect; the form-rendering actions return a view.
     *
     * A web resource controller has two kinds of action and they answer
     * differently — a screen *returns* a page, a form handler *redirects* — and
     * that split is the whole reason `create`/`edit` are separate from
     * `store`/`update`. A stub that returned a view from `store` would re-render
     * the form on submit and leave the browser's URL on the handler.
     *
     * The two helpers are the *global* `view()` and `redirect()`, not an injected
     * `$this->views`: the generated class is `final` with no constructor, so
     * there is nothing to inject into, and both helpers resolve their collaborator
     * through the container at call time. That is what lets a scaffold with no
     * dependencies stay honest — the response the action returns is the same one
     * an injected renderer would have produced.
     */
    public function test_the_admin_form_handlers_redirect_and_the_screens_render(): void
    {
        $this->command()->handle(['Admin/CompanyController'], [self::FLAG_RESOURCE => true]);

        $contents = $this->read('Admin/CompanyController.php');

        // Screens render a view — four of them: index, create, show, edit. The
        // count is the assertion: a five would mean a form handler returned a page
        // instead of a redirect.
        $this->assertSame(
            ['index', 'create', 'show', 'edit'],
            $this->methodsReturning($contents, 'return view(')
        );

        // Handlers redirect, and say something on arrival.
        $this->assertSame(
            ['store', 'update', 'destroy'],
            $this->methodsReturning($contents, 'return redirect()->route(')
        );

        $this->assertStringContainsString("->with('success'", $contents);
    }

    /**
     * The web stub's view names are derived from the class name, lower-cased.
     *
     * `CompanyController` renders `company.index`. The view path is a *file path*
     * and the route path is a *URL*; they are allowed to disagree about case, and
     * a stub that used the StudlyCase base for both would look for
     * `views/Company/index.php` — a directory no template convention produces.
     */
    public function test_the_web_stub_view_names_are_lower_cased(): void
    {
        $this->command()->handle(['Admin/CompanyController'], [self::FLAG_RESOURCE => true]);

        $contents = $this->read('Admin/CompanyController.php');

        $this->assertStringContainsString("'company.index'", $contents);
        $this->assertStringContainsString("'company.create'", $contents);
        $this->assertStringContainsString("'company.show'", $contents);
        $this->assertStringContainsString("'company.edit'", $contents);
        $this->assertStringContainsString("'company.index'", $contents);
    }

    /**
     * The collection and single-record keys are pluralized and singularized.
     *
     * A local variable, so a wrong guess is cheap — but `'$companys'` in every
     * generated controller is the kind of small wrongness that makes a scaffold
     * look untended, and the `y` → `ies` rule is the one a naive `+ 's'` gets
     * wrong on the names people actually use.
     *
     * @dataProvider pluralNames
     */
    public function test_the_web_stub_pluralizes_the_collection_key(string $class, string $items, string $item): void
    {
        $this->command()->handle(['Admin/' . $class], [self::FLAG_RESOURCE => true]);

        $contents = $this->read('Admin/' . $class . '.php');

        $this->assertStringContainsString("'{$items}' =>", $contents);
        $this->assertStringContainsString("'{$item}' =>", $contents);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function pluralNames(): array
    {
        return [
            'plain s' => ['CompanyController', 'companies', 'company'],
            'vowel before y' => ['SurveyController', 'surveys', 'survey'],
            // `Category` must not become `categorys`; `Survey` must not become
            // `surveies`. The two rules pull in opposite directions and are the
            // reason this is not a one-line helper.
            'sibilant' => ['AddressController', 'addresses', 'address'],
            'ch ending' => ['BatchController', 'batches', 'batch'],
        ];
    }

    /**
     * The generated web controller is loadable and extends nothing.
     *
     * Extending the REST base is the specific mistake this guards, and asserting
     * the *absence* of a parent is how it is caught: a controller with no `use`
     * for `Framework\Routing\Controller` could still `extends Controller` by
     * accident if a later edit added the import back.
     */
    public function test_the_admin_controller_extends_nothing(): void
    {
        $this->command()->handle(['Admin/CompanyController'], [self::FLAG_RESOURCE => true]);

        $reflection = $this->loadGenerated($this->read('Admin/CompanyController.php'));

        $this->assertFalse(
            $reflection->getParentClass(),
            'A web controller must not extend the REST base class — its helpers build WP_REST_Response objects.'
        );
    }

    /**
     * The web stub has no `#[Route]` attributes and registers no REST routes.
     *
     * Web routes are declared in `routes/web.php` through `adminPage()` and
     * `adminAction()`, not on the controller: a wp-admin screen's slug and a form
     * handler's `admin_post_{action}` name are routing decisions, and the
     * attribute mechanism is REST-only. So registering zero routes here is the
     * assertion, not an accident of the stub being unreadable.
     */
    public function test_the_admin_controller_registers_no_rest_routes(): void
    {
        $this->command()->handle(['Admin/CompanyController'], [self::FLAG_RESOURCE => true]);

        $contents = $this->read('Admin/CompanyController.php');

        // Asserted on the file, not by instantiating it: a web controller takes a
        // `ViewRenderer`, so loading one here would need a renderer this test has
        // no use for. The absence of the attribute is the whole claim — web routes
        // are declared in `routes/web.php` through `adminPage()`/`adminAction()`,
        // and the attribute mechanism is REST-only.
        $this->assertStringNotContainsString('#[Route(', $contents);
        $this->assertStringNotContainsString('Framework\Routing\Attributes\Route', $contents);
    }

    /**
     * `--invokable` produces one action and nothing else.
     *
     * The "nothing else" half matters as much as the `__invoke`: the failure
     * mode is a stub that appends `__invoke` to the default three, which reads
     * as a working single-action controller until the router mounts five routes
     * for it.
     */
    public function test_the_invokable_flag_generates_a_single_action_controller(): void
    {
        $this->command()->handle(['CompanyController'], [self::FLAG_INVOKABLE => true]);

        $contents = $this->read('CompanyController.php');

        $this->assertStringContainsString('public function __invoke(', $contents);

        foreach (['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'] as $action) {
            $this->assertStringNotContainsString(
                'public function ' . $action . '(',
                $contents,
                "An invokable controller must not declare [{$action}]."
            );
        }
    }

    /**
     * The admin invokable controller is generated for the web surface.
     *
     * Same split as the resource shape, and the same silent failure if it is
     * wrong: a `WP_REST_Response` returned to the web dispatcher renders nothing
     * at all.
     */
    public function test_the_admin_invokable_stub_is_a_web_controller(): void
    {
        $this->command()->handle(['Admin/CompanyController'], [self::FLAG_INVOKABLE => true]);

        $contents = $this->read('Admin/CompanyController.php');

        $this->assertStringContainsString('ResponseInterface', $contents);
        $this->assertStringContainsString('public function __invoke(', $contents);
        $this->assertStringNotContainsString('WP_REST_Response', $contents);
        $this->assertStringNotContainsString('WP_REST_Request', $contents);
        $this->assertStringNotContainsString('#[Route(', $contents);
    }

    /**
     * An invokable controller is reachable through a callable-class route.
     *
     * ## Why this is a reflection check and not a string check
     *
     * `Route::post('company', CompanyController::class)` passes the *class name*
     * and nothing else, so PHP has to find `__invoke()` on it. That only works
     * for an invokable controller, and it is the kind of thing a stub edit breaks
     * silently: the file still parses, the class still loads, and registration
     * fails at run time with "not callable". Loading the generated file and
     * asking PHP directly is the only assertion that covers it.
     *
     * `eval` rather than `require`, because the generated file declares a class
     * in a fixed namespace — requiring a second copy in the same process would
     * be a fatal redeclaration. The `str_replace` cannot mangle anything real:
     * every controller in this suite is named `CompanyController`.
     */
    public function test_the_invokable_controller_is_actually_callable(): void
    {
        $this->command()->handle(['CompanyController'], [self::FLAG_INVOKABLE => true]);

        $this->assertSame(
            '__invoke',
            $this->loadedMethod($this->read('CompanyController.php')),
            'Route::controller(CompanyController::class) needs the class to be callable.'
        );
    }

    /**
     * A resource controller is *not* invokable, and the mount it documents is
     * the array form for exactly that reason.
     *
     * The complement of the test above: if the resource stub were callable, the
     * documented `Route::controller(CompanyController::class)` spelling in its
     * docblock would be the wrong advice.
     */
    public function test_the_resource_controller_is_mounted_by_action_not_by_being_callable(): void
    {
        $this->command()->handle(['CompanyController'], [self::FLAG_RESOURCE => true]);

        $this->assertNotSame('__invoke', $this->loadedMethod($this->read('CompanyController.php')));
    }

    /**
     * The route path drops the `Controller` suffix, so `CompanyController` does
     * not route at `/CompanyController`.
     *
     * A cosmetic-looking derivation with a real consequence: the path is what
     * appears in every `#[Route]` attribute and in the mount the post-write hint
     * prints, so a wrong one is a controller mounted at a URL nobody expected.
     *
     * Asserted against the *registered* paths rather than the file's text: the
     * class name legitimately appears throughout — it is the class, and the mount
     * block names it — so scanning the file for the string would fail on a
     * correct controller. What must not contain it is the route.
     */
    public function test_the_route_path_drops_the_controller_suffix(): void
    {
        $this->command()->handle(['CompanyController'], [self::FLAG_API => true]);

        $paths = $this->routesFrom($this->read('CompanyController.php'));

        // Every path is rooted at `Company`; none carries the `Controller` suffix.
        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('Controller', $path, "The route [{$path}] still carries the suffix.");
        }

        $this->assertStringStartsWith('GET Company', $paths[0]);
    }

    /**
     * The class literally named `Controller` keeps its name.
     *
     * Stripping the suffix unconditionally would leave an empty route path,
     * which on the REST side means the controller's actions mount at the
     * namespace root — colliding with every sibling the project registers. The
     * class name is the safe fallback precisely because it is never empty.
     */
    public function test_a_class_named_controller_does_not_get_an_empty_route_path(): void
    {
        $this->command()->handle(['Controller'], [self::FLAG_API => true]);

        $contents = $this->read('Controller.php');

        $this->assertStringContainsString("#[Route('GET', 'Controller')]", $contents);
        $this->assertStringNotContainsString("#[Route('GET', '')]", $contents);
    }

    /**
     * The sub-namespace still lands in the directory and the namespace, and the
     * route path does not inherit it.
     *
     * `APi/CompanyController` routes at `Company`, not `Api/Company`: the
     * directory is organization inside the project, and the route path is a URL
     * segment. A generator that fed the raw argument to the stub would produce
     * the latter, which is a path nested under a word that is a folder name.
     */
    public function test_the_flags_still_honour_the_sub_namespace(): void
    {
        $this->command()->handle(['Api/CompanyController'], [self::FLAG_API => true]);

        $contents = $this->read('Api/CompanyController.php');

        $this->assertStringContainsString('namespace App\Http\Controllers\Api;', $contents);
        $this->assertStringContainsString("#[Route('GET', 'Company')]", $contents);
        $this->assertStringNotContainsString('Api/Company', $contents);
    }

    /**
     * The sub-namespace is organization, not surface: `--api` wins under `Admin/`.
     *
     * Under the previous design the namespace decided the surface, so
     * `Admin/CompanyController --resource` was a web controller and there was no
     * way to put a REST controller under `Admin/`. The flag decides now, and this
     * is the case that pins it: the namespace still places the file, and the
     * flag still chooses the shape, and the two no longer interfere.
     *
     * @dataProvider namespacesForApi
     */
    public function test_the_api_flag_wins_regardless_of_the_namespace(string $name): void
    {
        $this->command()->handle([$name], [self::FLAG_API => true]);

        $contents = $this->read($name . '.php');

        $this->assertStringContainsString('WP_REST_Response', $contents);
        $this->assertStringContainsString('extends Controller', $contents);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function namespacesForApi(): array
    {
        return [
            'flat' => ['CompanyController'],
            'api' => ['Api/CompanyController'],
            // The case that changed: the flag decides, so `Admin/` no longer
            // forces the web shape.
            'admin' => ['Admin/CompanyController'],
            'unrelated namespace' => ['Reports/CompanyController'],
            // A namespace that merely *starts* with the same letters is not
            // `Admin`: the match is on the whole first segment, so this stays
            // REST rather than being caught by a `str_starts_with` that forgot
            // the segment boundary.
            'admin-ish prefix' => ['Administration/CompanyController'],
        ];
    }

    /**
     * And the web flag wins under any namespace too, which is the other half.
     *
     * `Api/CompanyController --resource` is a web controller: the flag says so,
     * and the namespace only decides where the file goes.
     */
    public function test_the_resource_flag_wins_regardless_of_the_namespace(): void
    {
        $this->command()->handle(['Api/CompanyController'], [self::FLAG_RESOURCE => true]);

        $contents = $this->read('Api/CompanyController.php');

        $this->assertStringContainsString('ResponseInterface', $contents);
        $this->assertStringNotContainsString('WP_REST_Response', $contents);
    }

    /**
     * A deeper namespace still lands in the right directory, and the flag still
     * decides the shape.
     *
     * Nesting is organization: `Admin/Reports/CompanyController --resource` is a
     * web controller two levels down, with the namespace and the directory both
     * carrying the full sub-namespace. Nothing about the depth changes which stub
     * is rendered — only the flag does that.
     */
    public function test_a_nested_namespace_is_still_the_web_surface_under_resource(): void
    {
        $this->command()->handle(['Admin/Reports/CompanyController'], [self::FLAG_RESOURCE => true]);

        $contents = $this->read('Admin/Reports/CompanyController.php');

        $this->assertStringContainsString('namespace App\Http\Controllers\Admin\Reports;', $contents);
        $this->assertStringContainsString('ResponseInterface', $contents);
        $this->assertStringNotContainsString('WP_REST_Response', $contents);
    }

    // ------------------------------------------------------ flag spellings

    /**
     * `--resource` and `resource` are the same request.
     *
     * WP-CLI hands a flag through without its dashes; a hand-built invocation
     * may keep them. Both are what the developer typed, so both must work —
     * silently ignoring one would generate the default stub and report success.
     */
    public function test_the_dashed_spelling_of_a_flag_works_too(): void
    {
        $this->command()->handle(['CompanyController'], ['--resource' => true]);

        $this->assertStringContainsString(
            'public function destroy(',
            $this->read('CompanyController.php')
        );
    }

    /**
     * `--resource=false` is not a request for the resource stub.
     *
     * The trap this closes: treating any non-empty string as truthy, so a
     * `--flag=false` — which some callers pass mechanically from a wrapper
     * script — quietly turns the feature *on*. The generated file would be a
     * valid controller of the wrong shape, and nothing would report it.
     */
    public function test_a_flag_explicitly_set_to_false_is_not_a_request_for_the_stub(): void
    {
        $this->command()->handle(['CompanyController'], [self::FLAG_RESOURCE => 'false']);

        $contents = $this->read('CompanyController.php');

        // The bare shape, which is what "not the resource stub" means: no
        // resource actions at all, rather than a *different* set of them.
        foreach (['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'] as $action) {
            $this->assertStringNotContainsString(
                'public function ' . $action . '(',
                $contents,
                "A false flag must not produce the resource shape; [{$action}] was found."
            );
        }
    }

    /**
     * Asking for both shapes is refused, and nothing is written.
     *
     * Resolving the conflict by silently preferring one would generate a
     * controller the caller did not ask for and report success — so the two are
     * rejected as a pair. "Nothing is written" is half the assertion: a refusal
     * that had already created the file would leave a half-answer on disk for
     * the caller to discover later.
     */
    public function test_asking_for_both_shapes_is_refused(): void
    {
        $command = $this->command();
        $command->handle(['CompanyController'], [self::FLAG_RESOURCE => true, self::FLAG_INVOKABLE => true]);

        $messages = implode("\n", $command->messages);

        $this->assertStringContainsString('--resource', $messages);
        $this->assertStringContainsString('--invokable', $messages);
        $this->assertFileDoesNotExist($this->workspace . '/app/Http/Controllers/CompanyController.php');
    }

    /**
     * A flag that cannot be resolved to a stub is named, not swallowed.
     *
     * The resource stub is the one an operator is most likely to have deleted or
     * renamed while customizing, and the failure has to say which file is
     * missing rather than reporting a controller that was never generated.
     */
    public function test_a_missing_shape_stub_is_reported_rather_than_written_empty(): void
    {
        // A project copy of the stub that shadows nothing is not the case here —
        // the fallback is the framework's own file, which ships. So this pins the
        // *shape* of the message instead: it must name the file it looked for.
        $command = $this->command();
        $command->handle(['CompanyController'], [self::FLAG_RESOURCE => 'true']);

        $this->assertFileExists($this->workspace . '/app/Http/Controllers/CompanyController.php');
        $this->assertStringContainsString('Controller created successfully', implode("\n", $command->messages));
    }

    /**
     * The generated API controller extends the framework's REST base class.
     *
     * The helpers the stub calls — `respond()`, `created()` — are inherited, so a
     * stub that dropped the `use` or the `extends` would fail at run time on the
     * first request rather than at generation. Asked of the loaded class, so the
     * parent link is real and not a string that appears in a comment.
     */
    public function test_the_generated_controller_extends_the_framework_controller(): void
    {
        $this->command()->handle(['CompanyController'], [self::FLAG_API => true]);

        $this->assertSame(
            Controller::class,
            $this->loadedParent($this->read('CompanyController.php'))
        );
    }

    // --------------------------------------------- the attribute round trip

    /**
     * Every `#[Route]` the API stub writes is a route the registrar accepts.
     *
     * ## Why this is driven through the real registrar
     *
     * The stub's attributes are only meaningful if the framework can read them,
     * and "read them" is a chain with several ways to break silently: the
     * attribute could name a path the compiler rejects, carry a verb the
     * registrar does not support, or use a `{id}` placeholder the route grammar
     * does not parse. None of those is visible in the generated text — the file
     * still parses and the attribute still *looks* right — so the generated
     * controller is loaded, reflected and registered for real, and the resulting
     * routes compared against the five the stub documents.
     *
     * This is also what makes {@see Controller::registerRoutes()} a tested code
     * path rather than a documented one: it has no production caller today, so a
     * stub that emitted attributes the registrar cannot digest would ship
     * unnoticed.
     */
    public function test_the_resource_stub_registers_real_routes(): void
    {
        $this->command()->handle(['CompanyController'], [self::FLAG_API => true]);

        $routes = $this->routesFrom($this->read('CompanyController.php'));

        $this->assertCount(5, $routes);
        $this->assertSame('GET Company', $routes[0]);
        $this->assertSame('POST Company', $routes[1]);
    }

    /**
     * A *web* invokable controller declares no routes of its own to read.
     *
     * `--invokable` is the one shape whose surface comes from the namespace, so
     * `Admin/…` is the web one. A web controller is mounted by `routes/web.php`,
     * not by `#[Route]` — so there is nothing here for a route list to collect.
     * Asserting that is the point: the failure mode is a web invokable stub that
     * keeps one REST-shaped attribute, which registers a second,
     * differently-typed route for the same class and only shows up as a
     * duplicate at dispatch.
     *
     * Reflection rather than a string scan, so this fails on an attribute the
     * stub could express in more than one spelling.
     */
    public function test_the_web_invokable_controller_registers_no_route_of_its_own(): void
    {
        $this->command()->handle(['Admin/CompanyController'], [self::FLAG_INVOKABLE => true]);

        $list = new GeneratedRouteList();
        $list->register($this->loadGenerated($this->read('Admin/CompanyController.php'))->getName());

        $this->assertSame([], $list->paths());
    }

    /**
     * And a *REST* invokable controller does declare one.
     *
     * The complement of the test above, and the reason `--invokable` cannot be a
     * single stub: outside `Admin/` the controller is mounted by the REST server,
     * so it carries a `#[Route]` — exactly the attribute the web stub must not
     * have. One stub cannot satisfy both.
     */
    public function test_the_rest_invokable_controller_registers_its_own_route(): void
    {
        $this->command()->handle(['CompanyController'], [self::FLAG_INVOKABLE => true]);

        $list = new GeneratedRouteList();
        $list->register($this->loadGenerated($this->read('CompanyController.php'))->getName());

        $this->assertSame(['POST Company'], $list->paths());
    }

    // -------------------------------------------------------------- helpers

    /**
     * Load a generated *REST* controller and collect the routes it declares.
     *
     * @return list<string>
     */
    private function routesFrom(string $contents): array
    {
        $controller = $this->loadGenerated($contents);

        $this->assertTrue($controller->isSubclassOf(Controller::class));

        $list = new GeneratedRouteList();
        $list->register($controller->getName());

        return $list->paths();
    }

    /**
     * The method names in a generated file that return a given expression.
     *
     * A shallow scan rather than a parse: the generated files are the stub's own
     * shape, so "which function body contains this call" is answerable by walking
     * lines and remembering the last `function` seen. A real parser here would be
     * more machinery than the assertion is worth.
     *
     * @return list<string>
     */
    private function methodsReturning(string $contents, string $needle): array
    {
        $found = [];
        $current = null;

        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/public function (\w+)\(/', $line, $m) === 1) {
                $current = $m[1];

                continue;
            }

            if ($current !== null && str_contains($line, $needle)) {
                $found[] = $current;
                // Once per method, so a body with two matching lines is not
                // reported twice.
                $current = null;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Evaluate a generated controller and report its parent class.
     *
     * @return class-string|null
     */
    private function loadedParent(string $contents): ?string
    {
        return $this->loadGenerated($contents)->getParentClass()?->getName() ?: null;
    }

    private function loadedMethod(string $contents): string
    {
        $class = $this->loadGenerated($contents);

        return $class->hasMethod('__invoke') ? '__invoke' : '';
    }

    /**
     * `eval` a generated controller into a uniquely-named class and reflect it.
     *
     * ## Why `eval` and not `require`
     *
     * The generated file declares `CompanyController` in a fixed namespace, and
     * several tests in this class generate that same name. `require`-ing two of
     * them in one process is a fatal redeclaration, and the class name is the one
     * thing a generator test cannot vary without stopping testing what it is
     * supposed to test.
     *
     * Only the top-level class name is renamed — the namespace, the parent, the
     * attributes and every method body are the bytes the generator wrote. That
     * keeps the assertions about the shipped stub rather than about a paraphrase
     * of it.
     */
    private function loadGenerated(string $contents): \ReflectionClass
    {
        $name = 'Generated' . bin2hex(random_bytes(6));

        $renamed = preg_replace('/\bclass CompanyController\b/', 'class ' . $name, $contents, 1);

        $this->assertIsString($renamed);

        // The namespace is read back off the generated file rather than assumed,
        // because the two surfaces declare different ones — `Admin/` renders
        // `...\Http\Controllers\Admin`. `eval` puts the class in whatever
        // namespace the file declares, so reflection has to be aimed at the same
        // one or `class_exists` reports a false negative on a perfectly good file.
        $this->assertSame(
            1,
            preg_match('/^namespace (.+);$/m', $renamed, $m),
            'A generated controller must declare its namespace.'
        );

        $namespace = $m[1];

        // `eval` needs the opening tag stripped; the `<?php` and `declare` lines
        // are the generated file's own and must not be re-emitted.
        $body = preg_replace('/^<\?php\s*/', '', $renamed, 1);

        $this->assertIsString($body);

        eval($body);

        $fqcn = $namespace . '\\' . $name;

        $this->assertTrue(class_exists($fqcn), "The generated controller [{$fqcn}] must be loadable PHP.");

        return new \ReflectionClass($fqcn);
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents($this->workspace . '/app/Http/Controllers/' . $relative);
    }

    /**
     * The project's PSR-4 map, with the workspace root pointed at so the paths
     * in it are relative to the scratch tree rather than to the real plugin.
     *
     * Read from the real `composer.json` — the map is a fact about this project
     * and a copy of it here would be a second source of truth that could drift.
     *
     * @return array<string, string>
     */
    private function psr4PrefixForApp(): array
    {
        $raw = (string) file_get_contents(dirname(__DIR__, 3) . '/composer.json');
        $data = json_decode($raw, true);

        $this->assertIsArray($data);

        /** @var array<string, string> $map */
        $map = $data['autoload']['psr-4'] ?? [];

        return $map;
    }

    private function command(): TestableMakeControllerCommand
    {
        return new TestableMakeControllerCommand($this->workspace);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }

        rmdir($path);
    }
}

/**
 * The real generator, aimed at a scratch tree, with its output captured.
 *
 * Only `getTargetPath()` is overridden — the namespace, the stub, the sub-path
 * derivation and the refusals are all the shipped class's own, so every
 * assertion above is about the code that ships rather than about the double.
 */
final class TestableMakeControllerCommand extends MakeControllerCommand
{
    /** @var list<string> */
    public array $messages = [];

    public function __construct(private readonly string $workspace)
    {
    }

    protected function getTargetPath(string $className): string
    {
        return $this->workspace . '/app/Http/Controllers/'
            . $this->subDirectoryPath()
            . $className . '.php';
    }

    protected function success(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function error(string $message): void
    {
        $this->messages[] = $message;
    }

    protected function info(string $message): void
    {
        $this->messages[] = $message;
    }
}

/**
 * A real `Route` list, collected without WordPress.
 *
 * ## Why this is a real `Router` and not a hand-rolled double
 *
 * The attribute path runs through `RouteRegistrar::add()`, which is private —
 * so a double cannot intercept it, and the only way to observe what
 * `registerRoutes()` produced is to let the shipped registrar and router do
 * their job. That is also the assertion worth having: the question is not
 * "does the generated stub call `controllerAction()`" but "does the framework
 * accept the attributes the stub wrote", and a double that replaced either
 * class would answer the first and assume the second.
 *
 * Nothing here reaches WordPress: `getRoutes()` reads the in-memory table, and
 * `flush()` — the one method with a side effect — is never called.
 */
final class GeneratedRouteList
{
    private Router $router;

    public function __construct()
    {
        // The container and resolver are constructor-injected collaborators the
        // collection path never touches: they are used to *dispatch* a request,
        // and nothing here dispatches anything.
        $container = new Container();

        $this->router = new Router(
            $container,
            new RouteResolver($container),
            new NamedRoutes()
        );
    }

    /**
     * Build a generated controller and run its attribute-declared routes in.
     *
     * ## Why the constructor arguments are resolved by reflection
     *
     * `registerRoutes()` is an instance method, so the controller has to be
     * built — and a generated controller may declare a dependency that has
     * nothing to do with the question being asked here. That class has no
     * constructor arguments of its own, so satisfying any parameter whose type is
     * instantiable with a bare `new` keeps this helper working for both surfaces
     * without the test having to know which one it is looking at.
     *
     * Note what this deliberately does *not* do: fall back to a mock. A web
     * controller that registered routes would still register them here, so the
     * "does this declare routes" assertions stay honest.
     *
     * ## A controller with no `registerRoutes()` collects nothing
     *
     * That is not a gap in the helper — it is the answer. `registerRoutes()` is
     * inherited from `Framework\Routing\Controller`, and the *web* stubs extend
     * nothing, so a web controller has no method to call and therefore declares
     * no routes. Detecting the method's absence and returning is what lets the
     * same helper serve both the REST assertion ("these paths") and the web one
     * ("no paths"), instead of the web case erroring on a missing method — which
     * would read as a broken test rather than as the property being checked.
     *
     * @param class-string $controller
     */
    public function register(string $controller): void
    {
        $reflection = new \ReflectionClass($controller);

        if (!$reflection->hasMethod('registerRoutes')) {
            return;
        }

        $instance = $reflection->newInstanceArgs(array_map(
            static fn (\ReflectionParameter $p) => new ($p->getType()->getName()),
            $reflection->getConstructor()?->getParameters() ?? []
        ));

        $instance->registerRoutes($this->router->registrar());
    }

    /**
     * Each collected route as `METHODS path`, in registration order.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_map(
            static fn (Route $route): string => implode(',', $route->getMethods()) . ' ' . $route->getPath(),
            $this->router->getRoutes()
        );
    }
}
