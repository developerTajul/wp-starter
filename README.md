# WP Mini Laravel

A Laravel-shaped architecture for WordPress plugins: a service container with
constructor injection, service providers with a `register`/`boot` lifecycle, a
configuration repository, dual routing (admin screens **and** a headless REST
API), a validation and resource layer, a database migration engine, and a
one-command rebrander.

This repository is a **starter project**, not a plugin you install as-is. Clone
it, rebrand it in one command, and build your plugin inside it — see
[Rebranding](#rebranding) before writing any code.

---

## Requirements

- PHP 8.2+
- WordPress 6.0+
- Composer

## Installation

```bash
composer install
```

`vendor/` is not committed. It is where the framework is symlinked in from
`./framework` (a Composer path repository), so `composer install` is what makes
`Framework\` resolvable at all. Without it the plugin shows an admin notice
rather than fataling on an uncaught `Error`.

Then create your environment file:

```bash
cp .env.example .env
```

> **Fill it in — do not leave it blank.** The template ships the identity keys
> with no value (`APP_NAME=`, `APP_PREFIX=`, `APP_NAMESPACE=`, `CLI_PREFIX=`,
> `REST_NAMESPACE=`, `APP_VIEWS_PATH=`). An empty value is *present* as far as
> `env()` is concerned, and `env()` coerces `''` to `null` — so
> `env('APP_NAME', 'WP Mini Laravel')` returns `null`, **not** the fallback. A
> blank `.env` therefore erases the config defaults rather than deferring to
> them. Either fill every identity key or delete the ones you do not need.

A filled-in file, with the starter's own values (the rebrander rewrites these for
you, so they only need to be right for the clone you are looking at):

```dotenv
APP_NAME="WP Mini Laravel"
APP_ENV=local
APP_DEBUG=true
APP_PREFIX=wp_mini_laravel_
APP_NAMESPACE=App
CLI_PREFIX=wp_mini
REST_NAMESPACE=wp-mini-laravel/v1
APP_VIEWS_PATH=views
API_SECRET_KEY=your_secret
```

`.env` is gitignored; `.env.example` is committed.

---

## The Three-Zone Architecture

The tree is split into three zones with a strict rule about which may depend on
which. Getting this boundary right is what keeps a project upgradeable.

| Zone | Namespace | Whose | Rebranded? | Depends on |
| --- | --- | --- | --- | --- |
| `framework/` | `Framework\` | The library's | **No** | its own Composer deps |
| `app/` | `App\` | Yours | **Yes** | `Framework\`, `Core\` |
| `core/` | `Core\` | Yours | **Yes** | nothing — `Core\` and PHP builtins only |

The two vertical layers sit *under* the namespace root rather than beside it.
In the starter the root is empty, so they read as `App\` and `Core\`; after a
rebrand to `--namespace=LeadBridge` they become `LeadBridge\App\` and
`LeadBridge\Core\`. The layer names are structural — they are what a `use`
statement uses to say which zone a class came from — so the rebrand prefixes
them and never flattens them to a bare root.

### `framework/` — the reusable machinery

A separate Composer package (`devlinkstudios/wp-mini-laravel-framework`) consumed
through a path repository and symlinked into `vendor/`. It carries the container
wiring, config repository, routing, HTTP layer, validation, console and
migrations.

**This zone is read-only during a rebrand.** Its `Framework\` namespace belongs
to the library; renaming it here forks the library, and every future framework
update becomes a merge. If you genuinely intend to fork, do it in its own
repository and depend on your fork.

### `app/` — your application

Everything specific to *this* plugin: controllers, middleware, resources,
providers, console commands, repositories, and the `App\Kernel` that registers
them. This is where most of your work happens.

### `core/` — your domain

Framework-agnostic domain logic — entities, value objects, contracts and the
services that orchestrate them. The boundary is enforced by what it imports:
`core/` today imports only `Core\` classes and `Throwable`. No `Framework\`, no
`App\`, no WordPress function. That is what lets `NoteService` be tested with an
in-memory repository and no CMS loaded.

The rule that keeps this honest: **`core/` never depends on `app/`.** Dependencies
point inward — `app/` resolves a `Core\` contract (`NoteRepositoryContract`,
`TransactionManagerContract`), and `RepositoryServiceProvider` binds it to a
`$wpdb`-backed adapter.

---

## Directory Structure

```
wp-mini-laravel.php           Entry file: .env, path.base, kernel bootstrap, boot hook.
.env / .env.example           Environment variables (.env is gitignored).
composer.json                 Dependencies, PSR-4 maps, the `rebrand` script.

app/                          YOUR APPLICATION  (App\)
  Kernel.php                    Registers the providers listed in config/app.php.
  Console/Kernel.php            The WP-CLI command registry.
  Console/Command.php           Base class for commands you write.
  Database/WpTransactionManager $wpdb-backed implementation of the Core contract.
  Http/Controllers/
    Admin/DashboardController     The admin-screen example (injects ViewRenderer).
    Api/LeadController            The REST example (resources, 404 shape, notes gate).
  Http/Middleware/RequireLeadRead.php   A route guard: extends RequireCapability.
  Http/Resources/LeadResource.php       The public shape of a lead.
  Providers/                    AppServiceProvider, RepositoryServiceProvider, RouteServiceProvider.
  Repositories/                 WpNoteRepository, WpNoteAuditRepository.

core/                         YOUR DOMAIN  (Core\) — imports nothing outside Core\
  Note/Domain/                  NoteEntity, NoteRepositoryContract,
                                NoteAuditEntry, NoteAuditContract.
  Note/Application/NoteService  Use-case orchestration over the contracts.
  Shared/Result                 Success/failure value object.
  Shared/PaginatedResult        A page of results plus its total.
  Shared/Contracts/TransactionManagerContract

config/app.php                Identity, paths and the provider list.
routes/api.php                REST routes.
routes/web.php                Web routes (admin screens, form handlers, front-end pages).
views/layout.php              The page shell — takes $title and $content.
views/dashboard.php           The screen body.
database/migrations/          Versioned schema changes.

framework/                    THE LIBRARY  (Framework\) — never rebranded
  Config/Config.php             The static configuration repository.
  Console/                      Console kernel, Command base, make:* generators.
  Database/                     Migration base class, Migrator, MigrationFile, migrate:* commands.
  Http/                         Request, responses, middleware Pipeline, FormRequest.
  Routing/                      Router, adapters, Route attribute, ParameterBinder, middleware.
  Support/helpers.php           env(), app(), config(), base_path(), view(), e(), old().
  Support/ViewRenderer.php      Dotted view-name resolution.
  Support/JsonResource.php      The resource base class.
  Validation/Validator.php      The rule engine behind FormRequest.
  bin/init-plugin.php           The rebrander (+ lib/Rebrander.php, lib/RebrandPlan.php).

stubs/                        Templates the make:* commands render.
tests/                        PHPUnit suite — WordPress is stubbed, never loaded.
```

---

## Dotenv Support

Environment variables are managed with
[vlucas/phpdotenv](https://github.com/vlucas/phpdotenv) and loaded in the entry
file immediately after Composer's autoloader:

```php
if (class_exists(\Dotenv\Dotenv::class)) {
    \Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}
```

`safeLoad()` (not `load()`) means the plugin **does not crash** when no `.env`
file exists — a production host that supplies variables at the server level
keeps working. The `class_exists()` guard is there because the entry file runs
before `composer install` has necessarily happened.

### The `env()` helper

```php
env('APP_ENV', 'production');
```

It checks `$_ENV`, then `$_SERVER`, then `getenv()`, and coerces Laravel-style
string values:

| Written in `.env` | Resolves to |
| --- | --- |
| `true` / `false` | `bool` |
| `(true)` / `(false)` | `bool` |
| `(null)` | `null` |
| `(empty)` | `''` |
| `KEY=` (present, empty) | `null` |
| key absent entirely | the second argument, uncoerced |

Two consequences worth internalising:

- **An empty value is not an absent one.** `KEY=` resolves to `null` and
  therefore *does not* fall through to the default — see the warning in
  [Installation](#installation).
- **Quote any value containing a space.** phpdotenv's parser rejects unquoted
  whitespace: `APP_NAME="Lead Bridge"`.

### Config over env

**`env()` is only ever called inside `config/*.php`.** Nothing in `app/`,
`core/`, `routes/`, `views/` or `tests/` reads the environment directly. Config
maps each variable once, with a fallback, and the rest of the application reads
it through the `config()` helper or an injected `Framework\Config\Config`:

```php
// config/app.php
'name' => env('APP_NAME', 'WP Mini Laravel'),

// anywhere in app/ or core/
config('app.name');

// or, in a class the container builds:
public function __construct(private readonly Config $config) {}
$this->config->get('app.name');
```

`Framework\Config\Config` is a **static** class, not a container binding — the
`config()` helper calls `Config::get()` directly. Inject the class (not an
instance resolved from the container) when a constructor needs a value.

This is also why the plugin keeps working under PHPUnit, WP-CLI, and any context
that boots before the entry file: the fallback in `config/app.php` is a real
value, not a missing one.

---

## 🚀 Routing System

### Overview & Philosophy

WordPress spreads routing across four unrelated mechanisms — `admin_menu`,
`admin_post_{action}`, `rest_api_init`, and `template_redirect` — each with its
own way of registering a handler and its own way of addressing one. WP Mini
Laravel puts Laravel's routing API in front of all four, so you write routes once
and the framework picks the mechanism:

- **`routes/web.php`** — the **admin and front-end surface**: `wp-admin` screens,
  menu and submenu entries, `admin-post.php` form handlers, and front-end pages.
- **`routes/api.php`** — the **headless REST surface**: JSON endpoints published
  under `/wp-json/{namespace}/`.

Both files `use Framework\Support\Facades\Route;` and both call `Route::get(...)`.
Which surface a route lands on is decided by **which file it is written in**, not
by anything in the declaration — see
[How the `Route` facade knows which router it is talking to](#how-the-route-facade-knows-which-router-it-is-talking-to).
That split is the whole design: a `leads` route in `web.php` is an admin screen,
and the *same* `leads` route in `api.php` is a REST endpoint. Neither file needs
to know the other exists.

### Admin Menus & Submenus (`web.php`)

A top-level admin menu is a `Route::get()` with `->withOptions()`. The route's
**path is the menu slug** — it is handed to `add_menu_page()` unchanged, so
`Route::get('leads', ...)` registers `admin.php?page=leads`.

```php
use App\Http\Controllers\Admin\LeadController;
use Framework\Support\Facades\Route;

Route::get('leads', [LeadController::class, 'index'])
    ->name('leads.index')
    ->withOptions([
        'page_title' => 'Leads',        // <title> and the on-screen heading
        'menu_title' => 'Leads',        // the sidebar label (defaults to page_title)
        'capability' => 'list_users',   // who may see and open the screen
        'icon'       => 'dashicons-email',
        'position'   => 26,             // slot in the sidebar; lower is higher up
    ]);
```

| Option | What it does |
| --- | --- |
| `page_title` | The screen's heading and document title. |
| `menu_title` | The sidebar label. Defaults to `page_title` — set it when the menu needs to be shorter than the heading. |
| `capability` | The WordPress capability required to see *and* open the screen. This is a menu-level guard, not a route middleware. |
| `icon` | A [Dashicon](https://developer.wordpress.org/resource/dashicons/) class, a URL, or `data:image/svg+xml;base64,…`. |
| `position` | Where the menu sits in the sidebar. Defaults to the bottom. Reserve a gap when adding several — the values are compared numerically. |
| `parent_slug` | **Makes this a submenu.** See below. |

#### Submenus

A **submenu** is the same call plus a `parent_slug` naming its parent's slug:

```php
Route::get(LeadController::CREATE_SLUG, [LeadController::class, 'create'])
    ->name('leads.create')
    ->withOptions([
        'page_title'  => 'Add a lead',
        'menu_title'  => 'Add a lead',
        'capability'  => 'list_users',
        'parent_slug' => LeadController::SCREEN_SLUG,  // 'leads'
    ]);
```

> **Declare the parent before the child.** WordPress back-fills the parent's own
> submenu entry only if the parent is already registered. Reversed, the top-level
> menu takes its *first submenu item's* title and the parent screen becomes
> unreachable — with no error, because both menus registered successfully.

#### Explicit surfaces

The verb decides the surface: a `get()` is an admin screen, a `post()` is a form
handler. When the verb does not say — a GET that is a front-end page, a POST that
is not a form submission — name the surface instead:

| Helper | WordPress mechanism | Use for |
| --- | --- | --- |
| `get($slug, $action)` | `admin_menu` | `wp-admin` screens |
| `post($slug, $action)` | `admin_post_{action}` | form submissions |
| `put`/`patch`/`delete` | `admin_post_{action}` | form submissions via `_method` |
| `page($url, $action)` | `template_redirect` | front-end pages |
| `adminPage()` / `adminAction()` | as above | the same decisions, named explicitly |

The action is Laravel's pair — `[LeadController::class, 'index']` — and the class
is resolved from the container at dispatch, so its constructor dependencies are
injected. `LeadController::class, 'index'` as two arguments means the same thing,
which is what `#[Route]`-attributed controllers and `RouteRegistrar::controller()`
emit; both work.

**In a web routes file a bare `get()` is an admin screen, not a front-end page.**
WordPress has no general front-end router, so a front-end request is the case
that has to be asked for by name — `page('thank-you', [...])`. The other two
surfaces follow from the verb, which is what lets a web routes file read like a
Laravel one.

A screen callback must **return** a string or a `ResponseInterface` — an admin
page returns rather than exits, so WordPress can render its chrome around it.
Returning an array is a REST idiom and is refused with a message naming the
route, because the alternative is the literal text "Array" on the page.

### Form Submissions & Actions (`web.php`)

`Route::post()` in a web routes file **is** a WordPress form handler. The
adapter publishes it on `admin_post_{action}` — the hook WordPress fires when a
form posts to `admin-post.php` — so you never touch `admin_post_*` or
`admin_post_nopriv_*` by hand:

```php
Route::post('leads/store', [LeadController::class, 'store'])
    ->name('store')
    ->withOptions(['action' => LeadController::STORE_ACTION])
    ->middleware([
        Authenticate::class,
        'capability:' . LeadController::CAPABILITY,
        'nonce:' . LeadController::STORE_ACTION,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
        SanitizeInput::class,
    ]);
```

The `action` option is optional — the name is derived from the route name or
path. **Set it explicitly when a form must post to a known string**, because the
derivation is not obvious: non-POST verbs get a suffix so that PUT and DELETE do
not share one hook. Declaring it (here, as a controller constant) keeps the
routes file, the nonce middleware and the form's hidden `action` field reading a
single string. Every route registered this way also exposes both
`admin_post_{action}` and `admin_post_nopriv_{action}`, so the handler decides
who may run it rather than WordPress silently 404ing a logged-out submit.

Form input arrives as a `Framework\Http\Request`; `$request->input('name')` reads
it, and validation lives in a `FormRequest` subclass.

### Route Groups

`Route::group()` shares attributes across every route declared inside its
callback. Three keys are supported, and they **compose across nesting**:

```php
Route::group(['as' => 'leads.', 'prefix' => 'leads', 'middleware' => [Authenticate::class]], function (): void {
    Route::get('all', [LeadController::class, 'index'])->name('index');   // name: leads.index
    Route::get('new', [LeadController::class, 'create'])->name('create'); // name: leads.create
});
```

| Key | Effect |
| --- | --- |
| `as` | A **name** prefix. `['as' => 'leads.']` plus `->name('index')` gives `leads.index`. |
| `prefix` | A **path** prefix, joined the way a URI joins: `['prefix' => 'leads']` plus `Route::get('all', ...)` registers the screen `admin.php?page=leads/all`. |
| `middleware` | Middleware applied to every route in the group. |

`as` and `prefix` are independent — `['prefix' => 'leads', 'as' => 'leads.']` is
the ordinary pairing, but grouping *paths* under a name prefix, or the reverse, is
legal and sometimes right.

Names compose rather than replace, so nesting works the way path prefixes do:

```php
Route::group(['as' => 'admin.'], function (): void {
    Route::group(['as' => 'leads.'], function (): void {
        Route::get('leads', [LeadController::class, 'index'])->name('index');
        // → admin.leads.index
    });
});
```

> **A group's prefix ends with the callback.** Path, name and middleware state are
> restored on the way out, so a route declared *after* the group does not inherit
> anything from it. A route that picks up a prefix nobody gave it is the failure
> this prevents.

### Parameterized Middleware

Middleware may take **parameters**, passed after a colon and split on commas —
Laravel's syntax, resolved by the HTTP pipeline:

```php
->middleware([
    'capability:manage_options',        // one argument
    'capability:edit_posts,publish_posts',  // two: all of them are required
    'nonce:leads_store',
    'nonce:leads_store,my_nonce_field', // action, then field name
])
```

Each parameter arrives as an extra argument, which the middleware receives with a
variadic:

```php
public function handle(Request $request, Closure $next, string ...$arguments): ResponseInterface
```

The entry is split at the **first** colon only, so a parameter may itself contain
one — `'redirect:https://example.test'` passes the whole URL. Class names that
already exist are never split, so an anonymous class name (which contains a colon)
is safe.

> **Passing parameters to middleware that takes none is refused, not ignored.**
> The container would drop them silently, and a silently-dropped
> `capability:manage_options` is an authorization check that never runs while the
> route still looks protected. The pipeline reports it at dispatch instead.

#### Built-in core middleware

Short aliases are registered in `Framework\Http\Middleware\MiddlewareAliases`;
the class names work too.

| Alias | Class | What it does |
| --- | --- | --- |
| `capability` | `CheckCapability` | Requires the capability named as a parameter. All comma-separated capabilities must be held. **Fails closed** — an empty parameter or a missing `current_user_can` denies with 403. |
| `nonce` | `VerifyNonce` | Verifies `_wpnonce` against the named action. An optional second parameter renames the field. **An empty action is refused**, because WordPress's `-1` is a specific action, not a wildcard. |
| `trim` | `TrimStrings` | Trims leading/trailing whitespace from string input, at any nesting depth. |
| `null` | `ConvertEmptyStringsToNull` | Turns `''` into `null`. `'0'` is left alone. |
| `sanitize` | `SanitizeInput` | `wp_unslash()` then `sanitize_text_field()` — the WordPress baseline. Collapses newlines, so do not enable it on a field that needs them. |
| `auth` | `Authenticate` | Requires a logged-in user. |
| `can` | `Authorize` | Capability check that **fails open** with a logged warning — prefer `capability` for anything that guards data. |

```php
use Framework\Http\Middleware\ConvertEmptyStringsToNull;
use Framework\Http\Middleware\SanitizeInput;
use Framework\Http\Middleware\TrimStrings;

->middleware([
    'capability:manage_options',
    'nonce:leads_store',
    TrimStrings::class,
    ConvertEmptyStringsToNull::class,
    SanitizeInput::class,
])
```

**Order matters.** The list above runs the authorization guards first and the
input transformers last, so a rejected request does no work. Trim before null:
`'   '` only becomes `''` — and therefore `null` — if it was trimmed first.

Register your own aliases in the same class:

```php
$aliases->alias('lead-access', \App\Http\Middleware\RequireLeadRead::class);
```

### The `route()` Helper (Intelligent URL Generation)

`route('name', $parameters)` turns a route name into the URL WordPress actually
serves it at. **Which WordPress function is used is decided by the route's
surface**, not by the caller — so a link and the screen it points at cannot
disagree:

| Route surface | Generated URL | WordPress function |
| --- | --- | --- |
| Admin screen | `admin.php?page=leads` | `admin_url()` |
| Form handler | `admin-post.php?action=leads_store` | `admin_url()` |
| REST endpoint | `/wp-json/wp-mini-laravel/v1/leads/42` | `rest_url()` |
| Front-end page | `thank-you` (plus a query string) | `home_url()` |

```php
// Admin screens and form handlers — the names routes/web.php declares.
route('dashboard');                    // .../wp-admin/admin.php?page=wp-mini-laravel
route('leads.index');                  // .../wp-admin/admin.php?page=leads
route('leads.create');                 // .../wp-admin/admin.php?page=leads-create
route('store');                        // .../wp-admin/admin-post.php?action=leads_store

// REST — the names routes/api.php declares.
route('api.leads.show', ['id' => 42]); // .../wp-json/wp-mini-laravel/v1/leads/42

// Front-end page.
route('thank-you', ['order' => 7]);    // .../thank-you?order=7
```

Placeholders (`{id}`) are substituted and `rawurlencode`d. On a front-end page,
parameters the path does not consume become a **query string** — so linking to
`page('leads')` with `['status' => 'new']` gives `?status=new` rather than
silently dropping the filter.

Two mistakes are told apart, with different messages:

- **Unknown name** — throws `InvalidArgumentException` and *lists the names that
  do exist*, so a typo is a glance rather than a hunt.
- **Missing placeholder** — `leads/{id}` with no `id` throws `RuntimeException`.
  It is not quietly rendered as `/leads/`, which is a valid-looking URL that
  resolves to nothing.

> **Outside WordPress, `route()` returns the path.** In a unit test or under
> WP-CLI the URL functions are not loaded, so you get `admin.php?page=leads`
> rather than a fatal — the part the framework computes, and the part a test can
> assert without stubbing a site URL.

A name declared in **both** domains is ambiguous and throws rather than picking
one, because a link to the wrong screen works, so nobody investigates. Give one a
distinct name with `->name()`.

There is no `route_has()` helper — `route()` throws by design. When you need to
fall back rather than throw, resolve `UrlGenerator` and ask it:

```php
$urls = app(\Framework\Routing\UrlGenerator::class);

$url = $urls->has('leads.optional') ? route('leads.optional') : '#';
```

### How the `Route` facade knows which router it is talking to

It does not, and that is deliberate. `routes/web.php` and `routes/api.php` both
call `Route::get(...)`, but one writes into the web adapter and the other into the
REST router — so `RouteLoader` aims the facade at the router it is loading *for*,
around the `require`, and restores the previous target afterwards. A routes file
is therefore domain-blind, exactly as a Laravel routes file is.

Two consequences worth knowing:

- **`Route::` is only callable while a routes file is loading** — or after
  something pointed it with `Route::setRouter($router)`, which is how a provider
  or a test drives it. Called from anywhere else it throws, naming `RouteLoader`.
- **A closure-based routes file still works.** `return function ($router) { ... };`
  is still honoured, so files written before the facade existed — including the
  fixtures in the suite — keep loading unchanged. The two styles can be mixed
  across files, though not within one: a file that uses the facade has nothing to
  return, and one that returns a closure has no reason to.

### Headless REST API

```php
use Framework\Support\Facades\Route;

Route::get('ping', static fn (): array => ['pong' => true]);

Route::get('leads', [LeadController::class, 'index'])
    ->middleware(RequireLeadRead::class);

Route::get('leads/{id}', [LeadController::class, 'show'])
    ->middleware(RequireLeadRead::class);
```

These publish under `/wp-json/{config('app.rest_namespace')}/`. The demo exposes:

| Endpoint | Response | Guard |
| --- | --- | --- |
| `GET /wp-json/wp-mini-laravel/v1/ping` | `{"pong":true}` | none — deliberately open |
| `GET /wp-json/wp-mini-laravel/v1/leads` | `{"data":[{...},{...}]}` | `RequireLeadRead` |
| `GET /wp-json/wp-mini-laravel/v1/leads/{id}` | `{"data":{...}}` | `RequireLeadRead` |
| …unknown `{id}` | `{"error":"not_found","message":"No lead with id 99."}` with **404** | `RequireLeadRead` |

A missing row is a 404 with an error body rather than `{}`, because a client that
receives an empty object has to guess whether the lead is absent or merely empty.

> **REST routes are public by default in WordPress.** The `leads` endpoints carry
> `RequireLeadRead` because they publish a lead's email address; without it an
> anonymous caller reads the whole table. `ping` is deliberately open because it
> returns nothing about anybody — the difference between the two is the point.

Middleware is registered **by class name**, with the capability set in that
class's constructor:

```php
final class RequireLeadRead extends RequireCapability
{
    public function __construct()
    {
        parent::__construct('list_users');
    }
}
```

> **This is the REST surface, and it differs from the web one.** REST middleware
> runs inside WordPress's `permission_callback`, a yes/no seam that can only deny —
> so it takes no parameters and there is **no** `'capability:list_users'` syntax
> here. Writing one registers a route whose middleware cannot be resolved: the
> resolution throws, the adapter converts the throw into a denial, and the endpoint
> 403s for *every* caller including administrators. It reads as a permissions bug
> in the wrong file. The parameterized syntax described
> [above](#parameterized-middleware) belongs to `routes/web.php`, where the
> pipeline owns the request and can pass arguments to `handle()`.

Routes can also be declared on a controller with the `#[Route]` attribute, which
`Framework\Routing\Controller::registerRoutes()` reflects over; `make:controller`
generates the attribute form.

### Resources and sparse fieldsets

A `JsonResource` decides the public shape of a payload, field by field. Adding a
database column does not add it to the response until somebody writes a line for
it in `toArray()` — that gap is the reason the class exists.

`only()` narrows a payload to a client-supplied field list (the JSON:API
sparse-fieldset convention). It is a **filter over fields the resource already
decided to emit**, never a permission: `?fields=notes` from a caller who was not
granted notes returns nothing, because the field was never mapped into the
payload for `only()` to keep. Combined with `when()`:

```php
'notes' => $this->when($this->includeNotes, $this->whenLoaded('notes')),
```

The inner `whenLoaded()` is not redundant. PHP evaluates every argument before
the call, so `when($flag, $this->notes)` reads `$this->notes` unconditionally —
including on a partial row with no `notes` key, where the read throws. The
condition was supposed to make that row safe; written that way it makes it throw
instead.

---

## WP-CLI Integration

Commands are registered in `app/Console/Kernel.php` and exposed under the prefix
from `config('app.cli_prefix')` — `wp_mini` by default.

```bash
wp wp_mini make:controller LeadController      # a controller class
wp wp_mini make:provider BillingServiceProvider
wp wp_mini make:route ReportController          # a route + controller
wp wp_mini make:migration create_leads_table
wp wp_mini migrate                              # run pending migrations
wp wp_mini migrate:rollback
wp wp_mini migrate:status
wp wp_mini route:list                           # every registered route
```

### Zero-config CLI: `php mini`

`mini` is the plugin's own CLI entry point, and it is the recommended way to run
commands. It gives you a Laravel-like experience with no setup at all:

```bash
php mini route:list                    # -> wp <CLI_PREFIX> route:list
php mini make:controller LeadController
php mini make:migration create_leads_table
php mini migrate
php mini migrate:status
php mini --verbose route:list          # report the prefix and binary it resolved
```

**Nothing to configure.** `php mini <command>` works on a fresh clone, before
`composer install`, on a machine with no WP-CLI:

- **The prefix is read from `.env` automatically.** Set `CLI_PREFIX=lead_bridge`
  and `php mini route:list` becomes `wp lead_bridge route:list` — no code change,
  no flag. Without a `.env` it falls back to the same `wp_mini` that
  `config/app.php` falls back to.
- **WP-CLI installs itself when it is missing.** If `wp` is not on your `PATH`,
  `mini` downloads `wp-cli.phar` once into a hidden `.cli-cache/` directory
  (gitignored) and reuses it from then on. A machine that already has WP-CLI
  never triggers a download.

It is a **forwarder**, not a second console: there is no command registry in it,
and it never needs to know what `route:list` is. Two things are resolved, and
then the command line is handed over intact — including stdin, so interactive
prompts and Ctrl-C behave exactly as they do under `wp`.

**Why the prefix is parsed from the file** rather than read through `config()`:
reading config means booting WordPress, which is the thing being invoked. So
`mini` reads `.env` itself, which is also what lets it work before
`composer install` — when a developer is most likely to need it.

`mini` validates the prefix before forwarding it, because the framework does not:
`config/app.php` accepts `env('CLI_PREFIX')` as written, so a value with a space
or a slash is accepted at boot and only shows up later as commands that cannot be
called. A malformed value is refused with the value quoted.

**WP-CLI resolution order** is your `PATH` first — preferring a real `wp` or a
`.phar` over the `wp.bat` proxy, since PHP cannot execute a batch file as a
script — then the local `.cli-cache/` download. Both cURL and PHP's stream
wrapper are tried for the download, because the two read their TLS trust anchors
from different ini settings and a machine can have a working HTTPS stack in one
and not the other.

`tests/manual/verify-mini-entrypoint.php` checks all of this against a real
WordPress — including the case that matters most, that a prefix the plugin has
not registered does *not* silently succeed.

### Troubleshooting: `bash: wp: command not found` (Windows + Git Bash)

On Windows, WP-CLI installs as `wp.bat`. **Git Bash (MINGW64) does not resolve
`.bat` files** the way `cmd.exe` and PowerShell do: it scans `PATH` for a file
named exactly `wp` with the executable bit set, tries no suffixes, and gives up.

So if you see this in Git Bash despite `wp-cli` being on your Windows `PATH`:

```bash
$ wp --info
bash: wp: command not found
```

…your PATH is not the problem. `wp.bat` is simply invisible to that shell.

**The fix is an extension-less `wp` shim beside your `wp.bat`**, in the same
directory. Create the file `wp` (no extension) next to `wp.bat`:

```sh
#!/bin/sh
# WP-CLI launcher for Git Bash (MINGW64) on Windows.
#
# Git Bash resolves a bare `wp` by scanning PATH for a file named exactly `wp`
# with the executable bit set. It does not try `.bat` / `.cmd` suffixes, so
# `wp.bat` beside this file is invisible to it and the shell reports
# `bash: wp: command not found` even though the directory is on PATH.

set -e

# Resolve this script's own directory, following symlinks so a copy linked
# onto PATH elsewhere still finds the phar and php beside the *real* file.
script="$0"

while [ -h "$script" ]; do
    target=$(readlink "$script")
    case "$target" in
        /*) script="$target" ;;
        *)  script="$(dirname -- "$script")/$target" ;;
    esac
done

here=$(cd -- "$(dirname -- "$script")" && pwd)

php_bin="$here/php.exe"
phar="$here/wp-cli.phar"

# Fall back to whatever php is on PATH when this file was copied somewhere
# without its siblings — a ~/bin install, say.
if [ ! -x "$php_bin" ]; then
    php_bin=$(command -v php || true)

    if [ -z "$php_bin" ]; then
        echo "wp: cannot find php.exe beside $0, and no php on PATH." >&2
        exit 1
    fi
fi

if [ ! -f "$phar" ]; then
    echo "wp: cannot find wp-cli.phar beside $0 (looked in $here)." >&2
    exit 1
fi

exec "$php_bin" "$phar" "$@"
```

Then make it executable and open a new Git Bash window:

```bash
chmod +x /path/to/wp-cli/wp
```

```bash
wp --info        # works now
```

Notes that matter:

- **`exec` is deliberate.** The wrapper replaces its own process rather than
  spawning a child, so stdin is inherited and exit codes pass through untouched.
  Interactive prompts, pipes and CI step failures all behave correctly.
- **`wp.bat` is left alone**, so `cmd.exe` and PowerShell keep working. Having
  both files side by side is the intended setup, not a conflict.
- **Put it where `wp.bat` already is** — that directory is already on your PATH,
  so no PATH edit is needed. A `~/bin` copy also works, provided `php` is on PATH.

> **Prefer to skip this entirely?** `php mini <command>` needs no global `wp` at
> all — it resolves WP-CLI itself, including downloading it when absent. The
> shim above is only for people who want the bare `wp` command in Git Bash.

---

`Framework\Console\Command` deliberately does **not** extend `WP_CLI_Command` —
WP-CLI accepts a plain callable, so extending it would couple every command to
WP-CLI being loaded merely to be *declared*, not to be run. `App\Console\Command`
exists as an empty subclass so project-wide output conventions have a home in
your namespace rather than in the library.

---

## Request Lifecycle

1. WordPress includes `wp-mini-laravel.php`; the `ABSPATH` guard runs first.
2. The entry file checks for `vendor/autoload.php` — notice and return if absent.
3. `.env` is loaded via `safeLoad()`, then an idempotency guard
   (`WP_MINI_LARAVEL_BOOTED`) stops a second include from re-running the boot.
4. `path.base` is bound, and `config/*.php` is loaded into `Config`.
5. `(new App\Kernel(app()))->bootstrap()` registers every provider in
   `config/app.php`.
6. `app()->boot()` runs on the `plugins_loaded` hook.
7. A request arrives; the routing provider picks the surface (REST, admin page,
   admin action, or front-end page) and dispatches through middleware to a
   controller.

Dependencies arrive by **constructor injection** through the container, which is
what makes a controller testable without WordPress:

```php
final class DashboardController
{
    public function __construct(private readonly ViewRenderer $views) {}
}
```

---

## Database Migrations

Migrations live in `database/migrations/`, named
`YYYY_MM_DD_HHMMSS_description.php`. The migrator `require`s each file and checks
that the class its filename promises exists; the class name is the description in
StudlyCase with the timestamp stripped. Each migration is one reversible step —
whatever `up()` creates, `down()` must remove.

The shipped example, `2026_11_05_120000_create_notes_tables.php`, shows the
house style: two tables in one migration, table names read from the repository
constants that query them, and every DDL failure reported with `last_error`.
Condensed to one table, in the namespace `make:migration` generates:

```php
namespace App\Database\Migrations;

use Framework\Database\Exceptions\MigrationException;
use Framework\Database\Migration;
use wpdb;

final class CreateLeadsTable extends Migration
{
    /**
     * Migrations are resolved from the container, so the handle is injected
     * rather than reached for through `global $wpdb` — which is what lets this
     * run against a test database without a WordPress install.
     */
    public function __construct(private ?wpdb $wpdb = null) {}

    public function up(): void
    {
        $this->execute(sprintf(
            'CREATE TABLE `%s` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `email` varchar(255) NOT NULL,
                `created_at` datetime NOT NULL,
                PRIMARY KEY (`id`)
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->db()->prefix . 'leads'
        ), 'create the leads table');
    }

    public function down(): void
    {
        $this->execute(
            sprintf('DROP TABLE IF EXISTS `%s`', $this->db()->prefix . 'leads'),
            'drop the leads table'
        );
    }

    /**
     * Run one DDL statement, reporting the database's own reason on failure.
     *
     * DDL implicitly commits, so there is nothing to roll back and no return
     * value to interpret beyond success or failure. Without `last_error` the
     * migrator's own report is "migration failed", which says nothing about why.
     */
    private function execute(string $sql, string $operation): void
    {
        $db = $this->db();

        if ($db->query($sql) === false) {
            throw new MigrationException(sprintf(
                'Could not %s: %s',
                $operation,
                $db->last_error !== '' ? $db->last_error : 'the database reported no error message'
            ));
        }
    }

    /**
     * Resolved on first use. The migrator constructs migrations during a
     * command's setup, which under WP-CLI can run before `$wpdb` is the global
     * handle; throwing here beats a null dereference three statements later.
     */
    private function db(): wpdb
    {
        if ($this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }

        if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof wpdb) {
            throw new MigrationException(
                'No $wpdb instance is available. Pass one to the constructor when '
                . 'running outside a loaded WordPress.'
            );
        }

        return $this->wpdb = $GLOBALS['wpdb'];
    }
}
```

There is deliberately **no schema-builder DSL**. Use raw `$wpdb` DDL.

**A note on `dbDelta()`.** The shipped example does not use it, and the reason is
that a migration already has the thing `dbDelta()` exists to substitute for — a
record of what has been applied — so a plain `CREATE TABLE` runs exactly once and
a failure is a failure rather than a diff that decided there was nothing to do.
`stubs/migration.stub`, which `make:migration` renders, **does** use `dbDelta()`
and `global $wpdb`, because that is WordPress's own idiom and a reasonable default
for generated code. The two styles are both supported; pick one per project
rather than per file.

Two further details:

- The tracking table is `{$wpdb->prefix}plugin_migrations` — keyed on the
  **WordPress** table prefix, not the plugin's. Rebranding therefore does not
  orphan applied migrations.
- Tables your migrations create still carry `$wpdb->prefix`. Those names are only
  yours to choose, never to rename after the fact.

---

## Rebranding

The rebrander lives at **`framework/bin/init-plugin.php`** and is exposed as a
Composer script. It parses **`--key=value` flags only** — positional arguments
are not parsed, and are rejected with the usage message:

```bash
composer rebrand -- \
  --name="Lead Bridge" \
  --slug=lead-bridge \
  --vendor=acme \
  --namespace=LeadBridge
```

Preview every change without writing anything:

```bash
composer rebrand -- --dry-run \
  --name="Lead Bridge" \
  --slug=lead-bridge \
  --vendor=acme \
  --namespace=LeadBridge
```

The four flags above are required and reported **together** when any is missing,
so one run shows the whole contract. Anything else is derived — you never have to
invent a REST namespace or a CLI prefix by hand (see the table below).

### What it rewrites — the hybrid pass

| Kind | Files | Example |
| --- | --- | --- |
| **Static structure** | Renamed entry file, `composer.json` name + PSR-4 maps, every `App\` / `Core\` namespace in `app/`, `core/`, `config/`, `routes/` and `tests/` | `App\` → `LeadBridge\App\`, `Core\` → `LeadBridge\Core\` |
| **Config fallbacks** | The second argument of every `env()` call in `config/app.php` | `env('APP_NAME', 'Lead Bridge')` |
| **Dotenv** | Identity keys in `.env.example`, and in `.env` when it exists | `APP_PREFIX="lead_bridge_"` |

`framework/` is never rewritten. Its `Framework\` namespace and its fallback
defaults are the library's own identity — the script prints what it left there so
the boundary is visible rather than mysterious. `.env` is rewritten when present
so a project that has already set up its environment does not keep naming the old
identity, which would win over the config fallbacks at runtime. The value the
rebrand writes for `APP_NAMESPACE` is single-quoted on purpose: phpdotenv reads a
backslash in a double-quoted value as the start of an escape sequence and refuses
to parse `\A`.

Two things are deliberately left alone even though they are yours:

- **`tests/Integration/RebranderTest.php`** holds fixtures that *describe* the
  starter's identity — they write `App\` and `Core\` into a scratch tree and
  assert what moved. Rewriting the file would move the fixture and the assertion
  independently and leave the rebranded project with a red suite.
- **Path segments under `Tests\`.** `tests/Integration/App/Repositories/` declares
  `Tests\Integration\App\Repositories`, where `App` is a directory name rather
  than the application layer. Only the `use App\...` import in that file moves.

### Identifiers

| Identifier | Default | Example | Derived from |
| --- | --- | --- | --- |
| Plugin name | `WP Mini Laravel` | `Lead Bridge` | `--name` |
| Slug / folder | `wp-mini-laravel` | `lead-bridge` | `--slug` |
| Constant prefix | `WP_MINI_LARAVEL_` | `LEAD_BRIDGE_` | `SNAKE(namespace)_` |
| Option prefix | `wp_mini_laravel_` | `lead_bridge_` | `SNAKE(slug)_` |
| CLI prefix | `wp_mini` | `leadbridge` | `LOWER(namespace)` |
| REST namespace | `wp-mini-laravel/v1` | `lead-bridge/v1` | `slug/v1` |
| App namespace | `App` | `LeadBridge\App` | `namespace\App` |
| Core namespace | `Core` | `LeadBridge\Core` | `namespace\Core` |

The four names that must be **globally unique** — option prefix, CLI prefix, REST
namespace and constant prefix — are the ones that break something when two
plugins share them. The rest are cosmetic. The script refuses a rebrand to any of
the default values, because that changes nothing while reporting success.

### After the rebrand

```bash
composer update && composer dump-autoload   # not optional — see below
composer test
```

### What breaks quietly

- **A mismatched PSR-4 namespace does not fail.** `composer dump-autoload`
  reports success; the classes simply stop resolving and the plugin boots
  nothing. Composer maps prefixes to directories and never checks they agree.
- **The option prefix is data, not a label.** Changing it on a live site orphans
  every stored row — settings become invisible.
- **A changed CLI prefix is a breaking change** for every script you have
  written. Nothing warns you; the old command stops existing.
- **A stale `composer.lock` after a package rename** gives a confusing "package
  not found" rather than "renamed".

---

## Testing

```bash
vendor/bin/phpunit                                    # the whole suite
vendor/bin/phpunit --filter PluginBootTest
vendor/bin/phpunit --testsuite Unit                   # skip the Integration suite
composer test                                         # same as vendor/bin/phpunit
composer test:coverage                                # requires xdebug or pcov
```

The suite does **not** load WordPress. `tests/bootstrap.php` installs a stub
layer for the WordPress functions and classes the framework touches, in a
deliberate order (stubs first, then Composer's autoloader, then the class stubs,
then the builtin doubles), so the whole framework is testable without a
WordPress install.

| Directory | What it holds |
| --- | --- |
| `tests/Unit/` | No infrastructure — pure logic against the stubs. |
| `tests/Integration/` | Adapters that really issue SQL, against in-memory doubles. |
| `tests/Support/` | The doubles: `FakeWpdb`, `WpStub`, `MigrationWorkspace`, recording migrations. |
| `tests/Fixtures/config/` | A rebranded config, for tests that must prove a value is *derived*. |
| `tests/manual/` | Runnable probes for behaviour that cannot be reached from PHPUnit. |

`phpunit.xml` sets `failOnWarning`, `failOnNotice` and `failOnDeprecation`, so a
sloppy test is a failing test rather than a footnote.

Anything about real HTTP behaviour should be verified against a live install —
a stubbed test for it would pass for the wrong reason. That is what
`tests/manual/verify-http-lifecycle.php` is for.

---

## Configuration

All keys live in `config/app.php` and are read through `config()`:

| Key | Env variable | Default |
| --- | --- | --- |
| `app.name` | `APP_NAME` | `WP Mini Laravel` |
| `app.env` | `APP_ENV` | `production` |
| `app.prefix` | `APP_PREFIX` | `wp_mini_laravel_` |
| `app.namespace` | `APP_NAMESPACE` | `App` |
| `app.views` | `APP_VIEWS_PATH` | `views` |
| `app.cli_prefix` | `CLI_PREFIX` | `wp_mini` |
| `app.rest_namespace` | `REST_NAMESPACE` | `wp-mini-laravel/v1` |
| `app.providers` | — | the service provider list |

`APP_DEBUG` ships in `.env` and `.env.example` but is **not yet mapped to a
config key** — nothing reads it. Add `'debug' => env('APP_DEBUG', false)` to
`config/app.php` if you want it.

**Never call `env()` outside `config/`.** See [Config over env](#config-over-env).

---

## License

MIT.
