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

## The `dbDelta()` Boundary

If you are about to add anything to the schema layer, read this first. It is the
single constraint that explains most of the migration engine's shape, and the one
whose violation produces failures that are hard to trace back to their cause.

**`dbDelta()` is used for `CREATE TABLE`, and for nothing else.**

### What `dbDelta()` is

It is WordPress's schema-diffing helper. It parses a `CREATE TABLE` statement,
compares it against the live table, and issues the `ALTER`s needed to make the
live table match. Applied to the right statement that is a genuine feature, and
`Schema::create()` relies on it — a re-run against a partly-existing table
converges instead of failing on the first duplicate.

### Why it cannot be used for anything else

`dbDelta()` can only diff tables the statement **creates**. A column being added
to an existing table appears in no `CREATE TABLE`, so a `dbDelta()` over an
`ALTER` is not a diff at all: it either does nothing, or — worse — re-asserts an
older shape it found somewhere else.

Consequences you will meet while extending the builder:

- **`Schema::table()` issues plain SQL**, through
  `DatabaseAdapterInterface::statement()`. Any future `dropColumn()`,
  `change()` or `renameColumn()` must go the same way. Routing an alteration
  through `dbDelta()` is the mistake this boundary exists to prevent.
- **Foreign keys are lifted out of the `CREATE`** (`WpDatabaseAdapter::separateConstraints()`)
  and applied afterwards as idempotent `ALTER`s. `dbDelta()`'s field parser has
  no concept of a constraint — it reads a line beginning `CONSTRAINT` as a
  *column* named `CONSTRAINT`. On a first run that is harmless, because the
  original `CREATE` executes verbatim. On the **second** run it diffs that
  phantom column and emits `ALTER TABLE … ADD COLUMN CONSTRAINT …` — a syntax
  error. Without the lift-out, every migration with a foreign key would succeed
  once and then be permanently un-runnable.
- **Those follow-up `ALTER`s are guarded by an `information_schema` lookup**,
  never by matching MySQL's error text — which differs between server versions
  and between languages.

### `dbDelta()` has its own dialect

Its parser is line-oriented, and a `CREATE TABLE` that is perfectly valid MySQL
can still be **misparsed** — which is worse than rejected, because the table is
created without the key and the failure surfaces later as duplicated rows.
`WpDatabaseAdapter::toDbDeltaStatement()` normalises the statement rather than
trusting callers to write in dbDelta's dialect. The three rules:

| Rule | Why |
| --- | --- |
| The table name must be **unquoted** | A backtick-quoted name is not recognised as a `CREATE` at all, and the statement is silently dropped. |
| `PRIMARY KEY (` must be `PRIMARY KEY  (` — **two** spaces | That is the literal `dbDelta()` searches for. One space is read as a column named `PRIMARY` with an unknown type. |
| **Named** keys are kept named | An unnamed clause normalises to a `KEY` with an **empty** name, which can never equal the real `SHOW INDEX` name — so every later run re-issues `ADD` for an index that already exists. On a unique index over duplicate rows that `ALTER` fails, which is the whole migration failing on its second execution. |

`dbDelta()` also requires `wp-admin/includes/upgrade.php`, which WordPress loads
for admin requests but **not** under WP-CLI's default bootstrap or during cron.
The adapter requires it itself. Without that, `wp wp_mini migrate` fails while the
identical migration works from the admin — historically the most common cause of
a migration that "works on my machine".

### What this means in practice

| You want to | Route |
| --- | --- |
| Create a table | `Schema::create()` → `dbDelta()` (converges on re-run) |
| Add a column or index to an existing table | `Schema::table()` → plain `ALTER` |
| Change, drop or rename a column | plain `ALTER` — **never** `dbDelta()`. Not built yet; the builder today can only *add* to an existing table. |
| Rewrite existing rows | `$wpdb` directly — see *When you need raw `$wpdb`* under Database Migrations |

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
  Support/helpers.php           env(), app(), config(), base_path(), view(), e(), old(),
                                route(), redirect(), to_route().
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

### Redirecting

A controller answers with a response, so `redirect()` and `to_route()` return a
`RedirectResponse` rather than sending one — you `return` it, exactly as in
Laravel:

```php
return redirect('/somewhere');
return redirect()->route('leads.index');
return to_route('leads.index', ['page' => 2]);
return to_route('leads.index')->with('notice', 'Saved.');
```

`to_route()` is the short form of `redirect()->route(...)`; both resolve the name
through `route()`, so an unknown name fails the same way with the same message
whichever spelling you use.

The name is resolved **when the redirect is built**, not when it is sent. A route
table only exists while a request is being handled, so a name that missed would
otherwise be discovered after the flash cookie was written and the response was
already on its way out.

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

### Controller shapes

`make:controller` generates a controller in one of four **shapes**. The flag
picks the shape, exactly as Laravel spells them:

```bash
wp wp_mini make:controller CompanyController               # bare, no actions
wp wp_mini make:controller CompanyController --resource    # seven web actions
wp wp_mini make:controller CompanyController --api         # five REST actions
wp wp_mini make:controller CompanyController --invokable   # a single __invoke()  (one action)
```

**The flag picks the surface.** `--resource` is a web controller — it renders
views and redirects form posts. `--api` is a REST controller — it returns
`WP_REST_Response` and is answered by WordPress's REST server. The namespace no
longer decides this: `Admin/CompanyController --api` is a REST controller
placed under `Admin/`, and `Api/CompanyController --resource` is a web one. The
path says *where the file goes*; the flag says *what it is*.

The two surfaces share no base class, no request type and no return type, so
they are different stubs rather than one file with a namespace swapped in:

| Flag | Stub | Extends | Takes | Returns | Actions |
|---|---|---|---|---|---|
| *(none)* | `controller.stub` | `Controller` | — | — | *(none)* |
| `--resource` | `controller.resource.stub` | *(nothing)* | `Request` | `ResponseInterface` | `index`, `create`, `store`, `show`, `edit`, `update`, `destroy` |
| `--api` | `controller.api.stub` | `Controller` | `WP_REST_Request` | `WP_REST_Response` | `index`, `store`, `show`, `update`, `destroy` |
| `--invokable` | `controller.api.invokable.stub` | `Controller` | `WP_REST_Request` | `WP_REST_Response` | `__invoke` |
| `--invokable` under `Admin/` | `controller.invokable.stub` | *(nothing)* | `Request` | `ResponseInterface` | `__invoke` |

The three shape flags are mutually exclusive; asking for two at once is refused
before anything is written. `--invokable` is the one shape with no surface flag
of its own, so its surface comes from the namespace: under `Admin/` it is a web
controller, anywhere else a REST one.

A `--resource` controller renders views and redirects with a flash message, and
is mounted with `Route::adminPage(...)` / `Route::adminAction(...)` in
`routes/web.php`. It is generated as a `final` class with no constructor — the
screens call the global `view()` helper and the form handlers answer with
`redirect()->route(...)` and a `success` flash:

```php
final class CompanyController
{
    public function index(): ResponseInterface
    {
        return view('company.index', ['companies' => []]);
    }

    public function store(Request $request): ResponseInterface
    {
        return redirect()->route('company.index')->with('success', 'Created.');
    }
}
```

An `--api` controller declares `#[Route]` attributes and is mounted with
`Route::controller(...)` in `routes/api.php`. The command prints the correct
mount for whichever shape it generated.

The web stubs name their redirect target `<name>.index` — `company.index` for
`CompanyController` — so a generated `store()` only works once you have given the
`index` route that name with `->name('company.index')`. A name that does not match
throws when the redirect is built, naming the routes that do exist.

Each shape is a separate file in `framework/stubs/`, so a project can override
one without touching the others by copying it into its own `stubs/` directory.
They are resolved through `StubLocator`, which prefers the project's copy and
falls back to the framework's.

`--resource`, `--api` and `--invokable` together are refused rather than
resolved: they name different files, and silently picking one would report
success for a controller nobody asked for.

> **The attributes are declarative; the mount is what publishes them.**
> `#[Route]` attributes describe each action in place, but they do not put the
> controller on the router by themselves — add the controller to
> `routes/api.php` for them to take effect.

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
wp wp_mini make:controller LeadController --resource    # all seven actions
wp wp_mini make:controller LeadController --invokable   # a single __invoke()
wp wp_mini make:request StoreCompanyRequest     # a form request
wp wp_mini make:provider BillingServiceProvider
wp wp_mini make:route ReportController          # a route + controller
wp wp_mini make:migration create_leads_table
wp wp_mini migrate                              # run pending migrations
wp wp_mini migrate:rollback                     # reverse the last batch
wp wp_mini migrate:status                       # what ran, in which batch
wp wp_mini migrate:refresh                      # reverse everything, then re-run
wp wp_mini migrate:fresh --force                # drop and rebuild (destroys data)
wp wp_mini migrate:dump --output=database/dumps/schema.php   # write the live schema out
wp wp_mini route:list                           # every registered route
```

### Zero-config CLI: `php mini`

`mini` is the plugin's own CLI entry point, and it is the recommended way to run
commands. It gives you a Laravel-like experience with no setup at all:

```bash
php mini route:list                    # -> wp <CLI_PREFIX> route:list
php mini make:controller LeadController
php mini make:controller LeadController --resource
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

The primary way to write one is the **fluent schema builder**. `make:migration`
renders it, and it is what every migration shipped in this project uses:

### `make:migration` does not guess which table you mean

The description you type is a **filename**, not a declaration of intent. So the
generator takes the table name from a flag or not at all:

```bash
wp wp_mini make:migration create_leads_table --create=leads   # Schema::create('leads')
wp wp_mini make:migration add_status_to_leads --table=leads   # Schema::table('leads')
wp wp_mini make:migration backfill_lead_sources               # scaffolds nothing
```

Each flag selects its own stub. `--create` scaffolds a `CREATE TABLE` and a
`down()` that drops the table; `--table` scaffolds an `ALTER` and a `down()` that
drops only the column the `up()` added. Without either, the generated file has an
empty body and a comment naming the two calls to reach for — pick one and write it.

**What changed and why.** Earlier versions read the description for a table:
`create_leads_table` yielded `leads`, and anything matching
`add_*_to_*`/`drop_*_from_*` yielded the trailing word. That was right often
enough to be trusted and silent when it was wrong — `create_leads_and_companies`
guessed nothing, a rename or a data migration guessed from a preposition, and the
result was a plausible-looking table name in the one line a developer is least
likely to re-read. A wrong table name in a scaffold is a destructive statement
waiting to be run, so the guess is gone.

Passing an unusable `--table=`/`--create=` value — anything but letters, digits,
underscores and dollar signs — is refused before any file is written, rather than
producing a migration that dies when it runs.

```php
namespace App\Database\Migrations;

use Framework\Database\Migration;
use Framework\Database\Schema\Blueprint;
use Framework\Database\Schema\Schema;

final class CreateLeadsTable extends Migration
{
    public function up(): void
    {
        Schema::create('leads', static function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
}
```

Note what is *not* in that snippet: no `$wpdb`, no `$wpdb->prefix`, no
`CREATE TABLE`, no `last_error` handling. Table names are written bare (`leads`,
not `wp_leads`) and the prefix is applied by the adapter, so the same migration
runs unchanged on a site whose `$table_prefix` differs.

### The builder

`Schema::create()` builds and runs a `CREATE TABLE`. `Schema::table()` is handed a
table that **already exists**, and each column defined in the callback becomes an
`ALTER TABLE` clause — this is what a migration that adds a column to a shipped
table uses. `Schema::drop()` and `Schema::dropIfExists()` are the same operation;
prefer the second in a `down()`, since a `down()` routinely runs against a
database where its `up()` never completed.

| Columns | Modifiers | Indexes | Foreign keys |
| --- | --- | --- | --- |
| `id()`, `string()`, `char()`, `text()`, `mediumText()`, `tinyText()`, `integer()`, `unsignedInteger()`, `unsignedBigInteger()`, `unsignedTinyInteger()`, `boolean()`, `datetime()`, `date()`, `time()`, `year()`, `timestamp()`, `decimal()`, `float()`, `double()`, `binary()`, `json()`, `enum()`, `set()`, `uuid()`, `ulid()`, `ipAddress()`, `macAddress()`, `timestamps()` | `nullable()`, `default()`, `useCurrent()`, `after()`, `first()` | `index()`, `indexColumn()`, `indexWith()`, `unique()`, `dropIndex()`, `dropUnique()` | `foreignId()`, `foreignUuid()`, `foreignUlid()`, `morphs()`, `nullableMorphs()`, `constrained()`, `cascadeOnDelete()`, `nullOnDelete()`, `restrictOnDelete()` |

Three of those are multi-column helpers rather than single types:

| Helper | Emits |
| --- | --- |
| `morphs('subject')` | `subject_type` varchar(191) + `subject_id` bigint unsigned, both `NOT NULL`, plus a composite index |
| `nullableMorphs('subject')` | the same two columns, both nullable — `morphs(…)->nullable()` would reach only the second |
| `softDeletes()` | a nullable `deleted_at` datetime. Deliberately **not** indexed: the useful index is composite and depends on what the table filters by |
| `rememberToken()` | a nullable `remember_token` varchar(100) |

**`json()` and `binary()` take no `default()`, and `enum()`/`set()` refuse one
too.** MySQL rejects a `DEFAULT` clause on a json or blob column outright, and
accepts one on an enum that is *outside* the member list — storing the empty
member rather than refusing it, which is a schema that quietly allows a value
nothing meant to permit. All four are refused at build time with that said.

`enum()` and `set()` also check the things MySQL checks but reports badly: an
empty list, an empty member (indistinguishable from "nothing selected"), and the
255-byte ceiling across the whole definition. Values are escaped as SQL literals,
so a member containing a quote cannot break out of the list.

Changing an existing table — all of these are `Schema::table()`, and all of them
compile into the one `ALTER TABLE` the callback describes:

| What | Method |
| --- | --- |
| Add columns and indexes | `string('slug')`, `index()`, `unique()`, … |
| Drop a column | `dropColumn('slug')` or `dropColumn(['a', 'b'])` |
| Rename a column | `renameColumn('body', 'content')` |
| Change a column's definition | `string('body', 500)->nullable()->change()` |
| Move a column | `string('title')->after('id')->change()`, or `->first()` |
| Drop an index | `dropIndex(['name'])`, `dropUnique(['name'])` |

`Schema::rename('notes', 'archive')` renames a *table*, and is the one alteration
that is not a blueprint method — it takes both names through the prefix and
throws if the source is absent.

Three behaviours in that table are deliberate and differ from Laravel:

- **`string()` defaults to 191, not 255.** 255 characters × 4 bytes overruns the
  767-byte limit a single-column index has on utf8mb4 under the row formats
  WordPress still supports. Ask for `string('x', 255)` if you need it and are not
  indexing it.
- **Indexing a `longtext` is refused at the call site**, rather than emitting a
  `KEY` that MySQL rejects with a message about key length.
- **`foreignId('company_id')->constrained()`** derives the table (`companies`)
  from the column name, and the referenced table is prefixed too — so
  `constrained('users')` correctly points at `wp_users` (or `leadbridge_users`).

### `change()` makes you restate the definition

`change()` is the one place this builder does not read what you mean from what
you wrote. It has no schema introspection behind it — adding Doctrine DBAL was
rejected, since the builder describes and the adapter translates and a third
thing doing both would sit across that seam — so it emits exactly the definition
the fluent chain produced:

```php
$table->string('body', 500)->nullable()->change();
// MODIFY COLUMN `body` varchar(500)      ← nullable, because NOT NULL was stripped
```

That means **whatever you do not restate is discarded.** A `->change()` without
`->nullable()` on a column that was nullable makes it `NOT NULL`; a default you
do not repeat is dropped. The upside is that nothing is inferred and so nothing
is silently guessed wrong. Contrast `renameColumn()`, which does read the current
definition back through the adapter — and therefore needs only two names.

`down()` for a `dropColumn()` is a re-`add` that comes back **empty**. There is
no schema-level undo for dropped data, and a migration that drops a column is
reversible only in the structural sense.

### Index replacement compiles to one statement

`dropIndex()` and `unique()` over the same columns are emitted as **one**
`ALTER TABLE` with comma-separated clauses, not two statements. This matters:
making an already-indexed column unique compiles to a DROP and an ADD naming the
same index, and split across two statements the ADD creates the unique index and
the DROP then removes it — leaving the column unindexed and the constraint
silently absent. Inside one statement MySQL evaluates DROP before ADD regardless
of source order, which is the only ordering that produces the intended result.

### `dbDelta()` is used for `CREATE TABLE` only

`Schema::create()` is built on WordPress's `dbDelta()`, so a re-run against a
partly-existing table converges instead of failing on the first duplicate.
`Schema::table()` deliberately is not — it issues plain `ALTER` statements.

That distinction, the parser rules `dbDelta()` imposes, and why foreign keys are
lifted out of the `CREATE` and applied separately are all covered in
**[The `dbDelta()` Boundary](#the-dbdelta-boundary)**. If you are about to add a
column type or an alteration to the builder, read that section first.

### When you need raw `$wpdb`

The builder covers schema definition. It does **not** cover data migrations, and
it has no query builder behind it — so a migration that rewrites existing rows
uses `$wpdb` directly. Migrations are resolved through the container, so the
handle can be injected rather than reached for through the global:

```php
namespace App\Database\Migrations;

use Framework\Database\Migration;
use wpdb;

final class NormalizeActivityTypes extends Migration
{
    public function __construct(private ?wpdb $wpdb = null) {}

    public function up(): void
    {
        // Runs once per row here; a real data migration should batch its
        // updates rather than issuing one statement per row.
        $this->db()->query(
            "UPDATE `{$this->db()->prefix}activities`
             SET `trackable_type` = REPLACE(`trackable_type`, 'App\\\\Models\\\\', '')
             WHERE `trackable_type` LIKE 'App\\\\Models\\\\%'"
        );
    }

    public function down(): void
    {
        // Deliberately not reversible: the previous fully-qualified names are
        // not recoverable from the abbreviated ones.
    }

    private function db(): wpdb
    {
        if ($this->wpdb instanceof wpdb) {
            return $this->wpdb;
        }

        if (!isset($GLOBALS['wpdb']) || !$GLOBALS['wpdb'] instanceof wpdb) {
            throw new \RuntimeException(
                'No $wpdb instance is available. Pass one to the constructor when '
                . 'running outside a loaded WordPress.'
            );
        }

        return $this->wpdb = $GLOBALS['wpdb'];
    }
}
```

A `down()` that genuinely cannot reverse its `up()` is a legitimate choice for a
data migration — say so in the docblock, as above, rather than writing a `down()`
that silently does the wrong thing.

> **Note on the two examples.** Raw `$wpdb` DDL for *schema* changes is not
> recommended here — it bypasses the prefix handling and the `dbDelta()`
> convergence above. Reach for `$wpdb` for **data**, and `Schema::` for
> **structure**.

### No-op migrations are supported

A migration whose `up()` and `down()` are both empty is valid and is recorded as
applied. This is how a project keeps migration history aligned with another
codebase it was ported from — `2026_10_18_120000_normalize_activities_trackable_type_to_morph_aliases.php`
is exactly that, kept as a no-op so batch ordering still matches the Laravel
project of origin.

### Commands

```bash
wp wp_mini make:migration create_leads_table   # scaffold; derives the class name
wp wp_mini make:migration add_status_to_leads --table=leads   # scaffold an ALTER
wp wp_mini make:migration create_leads_table --create=leads   # scaffold a CREATE
wp wp_mini migrate                             # apply every pending migration, as one batch
wp wp_mini migrate --pretend                   # print the SQL without running any of it
wp wp_mini migrate --step                      # one batch per migration, so each is reversible alone
wp wp_mini migrate:rollback                    # reverse the most recent batch
wp wp_mini migrate:rollback --batch=2          # reverse a specific batch
wp wp_mini migrate:status                      # every migration, its batch, and missing files
wp wp_mini migrate:status --pending            # only the ones that have not run
wp wp_mini migrate:reset                       # reverse every batch
wp wp_mini migrate:refresh                     # reverse every batch, then re-run them all
wp wp_mini migrate:fresh --force               # drop everything and rebuild (destroys data)
wp wp_mini migrate:dump --output=database/dumps/schema.php   # write the live schema out
```

`--batch=<n>` is the recovery path for a batch that failed partway: `migrate:status`
shows the number, and rolling back that batch specifically is what clears it.

`migrate:status --pending` narrows the listing to migrations that have not run, so a
deploy script can ask "is there anything to apply?" by reading the last line —
`0 pending` — rather than parsing the table. On an up-to-date database it prints
`Nothing pending` instead of an empty table, and files recorded as ran whose source
has been deleted are still reported: those cannot be run, so they are never
"pending", and a filtered listing is exactly where they would otherwise disappear.

### `migrate --step` gives every migration its own batch

A plain `migrate` stamps every migration it applies with one new batch number, so
`migrate:rollback` undoes the whole run. `--step` allocates a fresh batch per
migration instead, which turns rollback into a single-migration undo:

```bash
wp wp_mini migrate --step          # 3 migrations → batches 1, 2, 3
wp wp_mini migrate:rollback        # reverses only the last one
```

Use it when you are iterating on one migration and want to reverse just that one,
or when you are stepping through a batch you do not fully trust. Batches are still
numbered oldest-first, so migration order survives and `migrate:status` reads the
same way it always does.

`--step` composes with `--pretend`, and a pretend run consumes no batch numbers —
so `migrate --pretend` followed by a real `migrate` starts at batch 1, not batch 4.

### `migrate --pretend` prints the SQL and writes nothing

`--pretend` runs each pending migration's `up()` for real, but every statement
that reaches the schema layer is recorded instead of executed. The tracking table
is left untouched, so the next real `migrate` still sees those migrations as
pending — a pretend run never marks work as done that was not done.

```bash
wp wp_mini migrate --pretend
# Pretending: 1 statement(s) would have been issued. Nothing was written.
#   CREATE TABLE `wp_leads` (`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, ...)
```

**Read the output knowing what it cannot see.** The recording seam is the schema
adapter, which is where every `Schema::` call lands. A migration that also issues
raw `$wpdb->query()` calls — a data migration rewriting values, say — has those
calls neither recorded nor run, because the framework does not route them and so
has nothing to intercept. The command prints that caveat whenever it recorded
something, so a pretend run is never mistaken for a complete account of the work.

Probes are the other half of the same design. `Schema::hasColumn()` and friends
still execute while pretending: a migration that guards an `ADD COLUMN` on a probe
has to take the branch it really would, or the printed statements would describe a
decision nobody made.

**`migrate:refresh` and `migrate:fresh` are not interchangeable.** `refresh` walks
every batch newest-first and calls each migration's `down()` — so it is the
command that finds a broken `down()` — but a `down()` that throws aborts it
partway and leaves a half-reversed schema. `fresh` first reverses the tracked
migrations the same way (it also calls `down()`), then drops the tracking table
outright and re-migrates from empty. Use `refresh` when you want the reverse path
exercised and reported batch by batch; use `fresh` when you want the tracking
state itself thrown away — a reset batch counter and no history — rather than
reversed.

> **Known docblock drift.** `Migrator::dropAllTables()`'s docblock and
> `MigrateFreshCommand`'s both state that `migrate:fresh` "does not call `down()`
> at all", and explain why. The code does call it (`Migrator.php:492`). The
> docblocks describe an earlier implementation and have not been updated; trust
> the behaviour described above, not those two paragraphs.

### Migration events

Three actions fire around every migration, whether it is running forward or
being reversed. They are ordinary WordPress hooks, so you attach to them with
`add_action()` in a service provider or anywhere else that runs before the
migration command does:

| Action | Fires | Arguments |
| --- | --- | --- |
| `{prefix}migration_starting` | immediately before the body runs | `string $identifier`, `string $direction` |
| `{prefix}migration_finished` | after the body returned, **only on success** | `string $identifier`, `string $direction` |
| `{prefix}migration_failed` | when the body threw, before the exception propagates | `string $identifier`, `string $direction`, `Throwable $e` |

```php
add_action('wp_mini_laravel_migration_starting', function (string $identifier, string $direction): void {
    error_log("{$direction}: {$identifier}");
}, 10, 2);

add_action('wp_mini_laravel_migration_failed', function (string $identifier, string $direction, Throwable $e): void {
    // The exception the migration actually threw — not the MigrationException the
    // migrator wraps it in, which would match every failure equally.
    notify_ops($identifier, $e);
}, 10, 3);
```

**`{prefix}` is your plugin's prefix, read from `app.prefix`** — the same value
the rebrander rewrites. So a plugin rebranded to `lead_bridge` fires
`lead_bridge_migration_starting`, and no listener has to be renamed by hand. The
constants (`Migrator::HOOK_MIGRATION_STARTING` and siblings) hold the suffix; the
prefix is added at fire time.

Three details that decide whether a listener is useful:

- **`direction` is `'up'` or `'down'`.** One listener serves both paths; the
  alternative is six actions, three per direction, and a listener that has to
  subscribe to all of them to know which way the schema is moving.
- **`finished` does not fire on failure.** "Finished" that also means "failed" is
  a hook no listener can act on — a listener that cleans up after a successful
  migration would clean up after a broken one and hide the breakage. Subscribe to
  `failed` for that case.
- **`starting` fires before a failing migration too.** A listener that prepared
  something in `starting` needs to know it has to undo it, which it can only do
  if `starting` came first.

The events fire once per migration, in execution order, bracketing that
migration's own body — not once per batch.

### Concurrent runs are locked out

Every command that changes the schema — `migrate`, `migrate --step`,
`migrate:rollback`, `migrate:reset`, `migrate:refresh`, `migrate:fresh` — takes a
MySQL advisory lock for the duration of the run, and gives it back when the run
ends, including when a migration throws.

```bash
wp wp_mini migrate
# Timed out after 600 seconds waiting for the migration lock [wp_plugin_migrations_lock],
# which another process holds. No migration was run — this usually means another deploy
# or `migrate` is still in progress. Wait for it to finish and try again. ...
```

**What it prevents.** Two `migrate` runs reading "this migration is pending"
before either writes. The tracking table cannot stop that — both processes make
the same read — and there is no schema transaction to fall back on, because MySQL
DDL implicitly commits. So the second process waits instead of racing.

**Why MySQL's lock and not an option row.** A lock built from WordPress options
has to decide what to do when the holder dies: the row outlives the process, so
every later run blocks on a lock nobody holds, and expiring it needs a timeout
that is either too short (two live runs collide) or too long (a dead run blocks a
deploy). `GET_LOCK()` has no such problem: MySQL binds it to the *connection*, so
a process that dies takes its lock with it, immediately and correctly.

**The lock name carries `$wpdb->prefix`** — `wp_plugin_migrations_lock` on a
default install — so two plugins sharing one database do not serialise against
each other. They touch different tables and have no reason to wait.

**A `--pretend` run takes no lock.** It writes nothing and creates nothing, so
there is nothing to race with; locking would make a developer's harmless dry run
block a real deploy happening at the same time.

If a run reports a lock timeout and nothing is actually running, the holder has a
hung connection. The error message names the query that identifies it:

```sql
SELECT IS_USED_LOCK('wp_plugin_migrations_lock');   -- connection id, or NULL
```

### `migrate:dump` writes the schema you actually have

The migration history cannot tell you what the database looks like — a column
added by hand, a migration that half-applied, a table someone once created
through phpMyAdmin. `migrate:dump` reads the catalogue and writes the
`Schema::create()` calls that would rebuild what is there.

```bash
wp wp_mini migrate:dump --output=database/dumps/schema.php
wp wp_mini migrate:dump --output=database/dumps/notes.php --table=notes --table=leads
```

The destination is `--output`, not `--path`. `--path` is WP-CLI's own global
flag — `wp --path=/srv/site <command>` selects which WordPress installation to
run against — and WP-CLI reads it during bootstrap, before the command ever
sees its arguments. A command cannot shadow it, only lose to it.

```
Dumped 3 table(s) to database/dumps/schema.php
This is a snapshot of the schema as it is now, not a record of the migrations that produced it.
Read it before running it.
```

**The destination is a flag, never a default.** Writing into
`database/migrations/` would leave a generated file in every developer's tree the
first time they ran it — and running that file would apply a second, conflicting
definition of tables the real migrations already create. The file is refused if
it already exists, for the same reason: the documented workflow is dump, read,
adjust, keep, and silently replacing it would destroy the adjusted copy.

**With no `--table`, it dumps the project's tables** — everything carrying
`$wpdb->prefix`, minus WordPress's core tables and the `plugin_migrations`
tracking table. `--table` may be repeated; a comma-separated list was
deliberately not offered, since a caller copying names out of a database listing
has no reason to expect one argument to be parsed.

**What it will not do:**

- **Reconstruct the migration history.** It emits one `create` per table and has
  no way to know which migration produced which column.
- **Dump data.** Schema only.
- **Approximate.** An unrecognised column type is reported with the type in the
  message rather than falling back to `string()` — a dump that runs cleanly and
  creates the wrong column is the worst available outcome, because nothing
  errors. An auto-increment key that is not the `id` column is refused too,
  since `id()` hardcodes both the name and the type. And naming the tracking
  table is an error rather than a silent skip: a dumper that quietly dropped a
  table it was asked for would return a file that looks complete and is missing
  schema.

The output carries these limits in its own header comment, so a reader meets
them where it matters rather than in this file.

### Two details worth knowing before you rename anything

- The tracking table is `{$wpdb->prefix}plugin_migrations` — keyed on the
  **WordPress** table prefix, not the plugin's. Rebranding therefore does not
  orphan applied migrations.
- Tables your migrations create still carry `$wpdb->prefix`. Those names are only
  yours to choose, never to rename after the fact.

### Naming the class

`make:migration` derives the class from the filename: the timestamp is stripped
and the description becomes StudlyCase, so
`2026_11_05_120000_create_leads_table.php` declares `CreateLeadsTable`. Because
the name is derived, the file cannot be autoloaded by class name — the migrator
`require`s it by path and then checks the class exists.

The generator writes migrations into `App\Database\Migrations`. Two files in this
project (`..._create_notes_table.php` and `..._create_note_audit_table.php`)
declare `Database\Migrations` instead — a real inconsistency rather than a
supported variation, since the migrator itself is indifferent to the namespace but
the autoloader is not. If you write migrations by hand, match the generator.

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
