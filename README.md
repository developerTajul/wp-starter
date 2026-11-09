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
> `REST_NAMESPACE=`, `APP_VIEWS_PATH=`). An empty value is _present_ as far as
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

| Zone         | Namespace    | Whose         | Rebranded? | Depends on                              |
| ------------ | ------------ | ------------- | ---------- | --------------------------------------- |
| `framework/` | `Framework\` | The library's | **No**     | its own Composer deps                   |
| `app/`       | `App\`       | Yours         | **Yes**    | `Framework\`, `Core\`                   |
| `core/`      | `Core\`      | Yours         | **Yes**    | nothing — `Core\` and PHP builtins only |

The two vertical layers sit _under_ the namespace root rather than beside it.
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

Everything specific to _this_ plugin: controllers, middleware, resources,
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
  _column_ named `CONSTRAINT`. On a first run that is harmless, because the
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

| Rule                                                      | Why                                                                                                                                                                                                                                                                                                             |
| --------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| The table name must be **unquoted**                       | A backtick-quoted name is not recognised as a `CREATE` at all, and the statement is silently dropped.                                                                                                                                                                                                           |
| `PRIMARY KEY (` must be `PRIMARY KEY  (` — **two** spaces | That is the literal `dbDelta()` searches for. One space is read as a column named `PRIMARY` with an unknown type.                                                                                                                                                                                               |
| **Named** keys are kept named                             | An unnamed clause normalises to a `KEY` with an **empty** name, which can never equal the real `SHOW INDEX` name — so every later run re-issues `ADD` for an index that already exists. On a unique index over duplicate rows that `ALTER` fails, which is the whole migration failing on its second execution. |

`dbDelta()` also requires `wp-admin/includes/upgrade.php`, which WordPress loads
for admin requests but **not** under WP-CLI's default bootstrap or during cron.
The adapter requires it itself. Without that, `wp wp_mini migrate` fails while the
identical migration works from the admin — historically the most common cause of
a migration that "works on my machine".

### What this means in practice

| You want to                                | Route                                                                                                        |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------------ |
| Create a table                             | `Schema::create()` → `dbDelta()` (converges on re-run)                                                       |
| Add a column or index to an existing table | `Schema::table()` → plain `ALTER`                                                                            |
| Change, drop or rename a column            | plain `ALTER` — **never** `dbDelta()`. Not built yet; the builder today can only _add_ to an existing table. |
| Rewrite existing rows                      | `$wpdb` directly — see _When you need raw `$wpdb`_ under Database Migrations                                 |

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

| Written in `.env`       | Resolves to                    |
| ----------------------- | ------------------------------ |
| `true` / `false`        | `bool`                         |
| `(true)` / `(false)`    | `bool`                         |
| `(null)`                | `null`                         |
| `(empty)`               | `''`                           |
| `KEY=` (present, empty) | `null`                         |
| key absent entirely     | the second argument, uncoerced |

Two consequences worth internalising:

- **An empty value is not an absent one.** `KEY=` resolves to `null` and
  therefore _does not_ fall through to the default — see the warning in
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
and the _same_ `leads` route in `api.php` is a REST endpoint. Neither file needs
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

| Option        | What it does                                                                                                                                                |
| ------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `page_title`  | The screen's heading and document title.                                                                                                                    |
| `menu_title`  | The sidebar label. Defaults to `page_title` — set it when the menu needs to be shorter than the heading.                                                    |
| `capability`  | The WordPress capability required to see _and_ open the screen. This is a menu-level guard, not a route middleware.                                         |
| `icon`        | A [Dashicon](https://developer.wordpress.org/resource/dashicons/) class, a URL, or `data:image/svg+xml;base64,…`.                                           |
| `position`    | Where the menu sits in the sidebar. Omit it and WordPress places the entry itself. Reserve a gap when adding several — the values are compared numerically. |
| `parent_slug` | **Makes this a submenu.** See below.                                                                                                                        |

**These options are read through `AdminPage`, not off the route.** `AdminPage` is a
read-only value object built with `AdminPage::fromRoute($route)` (or
`AdminPage::fromArray($slug, $options)`), and it is what the adapter, `route:list`
and a view rendering the menu all read — so they cannot disagree about what a key
means or fall back to different defaults. `Route` itself stays domain-agnostic:
a REST endpoint has no menu, so the option bag is the escape hatch that keeps
`add_menu_page()`'s nine positional arguments from becoming nine methods on a DTO
that mostly serves REST.

Its accessors: `slug()`, `pageTitle()`, `menuTitle()`, `capability()`, `icon()`,
`position()`, `parentSlug()`, `isSubmenu()`, `action()`, `has($key)`,
`options()`.

Three of its behaviours are worth knowing before you set an option:

| Accessor      | Rule                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `pageTitle()` | `page_title` → `menu_title` → the slug, humanized. A page that declares only `menu_title` has said what it wants the header to say; humanizing the slug over the top of that would ignore a deliberate choice. `humanize()` capitalises the **first word only** — `lead-reports` becomes `Lead reports` — because over-capitalising an unset label reads worse than plain sentence case. A slug of only separators is returned as-is rather than becoming an empty title, and an empty slug becomes `Untitled`. |
| `position()`  | An integer, or a string that is a clean integer (`'26'`). **Anything else is `null`, not `0`** — `(int) 'top'` is `0`, which would silently pin the entry to the top of the menu.                                                                                                                                                                                                                                                                                                                               |
| `has($key)`   | A key given as `null` or `''` counts as **not given**, so `'icon' => ''` means "no icon" rather than "an empty icon that beats the default". A value that is not a string is likewise ignored rather than cast — a `menu_title` given as an array would otherwise render a menu entry literally labelled `Array`.                                                                                                                                                                                               |

`capability()` defaults to `manage_options`, matching `add_menu_page()`. That is a
default rather than an error on purpose: an admin screen with no capability
declared is the common case and is _correctly_ restricted, whereas refusing to
register would make the common case the broken one. WordPress enforces it before
the route's own middleware runs — it is the outer gate, and middleware is for what
a capability cannot express, such as a per-record ownership check.

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
> menu takes its _first submenu item's_ title and the parent screen becomes
> unreachable — with no error, because both menus registered successfully.

#### Explicit surfaces

The verb decides the surface: a `get()` is an admin screen, a `post()` is a form
handler. When the verb does not say — a GET that is a front-end page, a POST that
is not a form submission — name the surface instead:

| Helper                          | WordPress mechanism   | Use for                              |
| ------------------------------- | --------------------- | ------------------------------------ |
| `get($slug, $action)`           | `admin_menu`          | `wp-admin` screens                   |
| `post($slug, $action)`          | `admin_post_{action}` | form submissions                     |
| `put`/`patch`/`delete`          | `admin_post_{action}` | form submissions via `_method`       |
| `page($url, $action)`           | `template_redirect`   | front-end pages                      |
| `adminPage()` / `adminAction()` | as above              | the same decisions, named explicitly |

`adminPage()` is `get()` under a name that says what it means. `adminAction()`
takes a **fourth** argument the verb methods have no room for:

```php
Route::adminAction('leads/update', CompanyController::class, 'update', 'PUT');
```

The verb is stated separately because a form handler's verb is _not_ what
identifies it: the form itself sends a POST, and the `PUT` is what the route
declares so `_method` can be matched. Reaching for `Route::put()` to say the same
thing works, but the call no longer reads as "a form posts here".

The action is Laravel's pair — `[LeadController::class, 'index']` — and the class
is resolved from the container at dispatch, so its constructor dependencies are
injected. `LeadController::class, 'index'` as two arguments means the same thing,
which is what `#[Route]`-attributed controllers and `RouteRegistrar::controller()`
emit; both work.

**Where a pair and a callable differ is worth one sentence**, because the two look
alike and behave differently. A two-element list of strings — and _only_ that — is
read as a controller action, so the class is resolved from the container and even a
`static` method is invoked on an instance. Any other callable (a closure, or
`[$object, 'method']`) is registered as a **handler** through `Route::handle()`,
which means no constructor injection: there is no class for the container to
reflect on. Anything else — a three-element array, a string-keyed array — is
refused when the route is built rather than at dispatch, where the same mistake
would surface as a missing-method error naming no route.

**In a web routes file a bare `get()` is an admin screen, not a front-end page.**
WordPress has no general front-end router, so a front-end request is the case
that has to be asked for by name — `page('thank-you', [...])`. The other two
surfaces follow from the verb, which is what lets a web routes file read like a
Laravel one.

There is one place the bare `get()` says something else, and it is worth knowing
because the two rules point in opposite directions. `WebRouteRegistrar::get()`
**stamps** the admin-screen surface on the route, so a bare GET written through a
web routes file is a screen. `WordPressRouterAdapter::inferSurface()` is the
fallback used only for a `Route` assembled by hand and handed to `addRoute()` —
and it has no registrar to have stamped anything, so it reads the verb: non-mutating
becomes a front-end page, mutating becomes a form handler. Both are right for their
own caller. The inference is deliberately the _conservative_ pair, because claiming
an admin menu nobody asked for is the mistake that produces an unreachable screen.

An explicit `surface` option always wins over the inference, so a route that states
its surface is never overruled by its verb.

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
single string.

Every route registered this way exposes **both** `admin_post_{action}` and
`admin_post_nopriv_{action}`, so the handler decides who may run it rather than
WordPress silently 404ing a logged-out submit.

#### The action name, derived step by step

Walking `actionName()` in order, because the option above is the escape hatch from
a chain that has four steps:

| Step | Rule                                                                                                                                                                                                                                                            | Example                                                              |
| ---- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| 1    | An explicit `action` option wins outright.                                                                                                                                                                                                                      | `->withOptions(['action' => 'leads_store'])` → `leads_store`         |
| 2    | Otherwise the route's **name**, or its path when unnamed. A name is the identifier the author chose and already appears in the route table.                                                                                                                     | `->name('store')` → `store`                                          |
| 3    | Placeholders are removed **whole**, through `RouteCompiler::substitute()` — not by matching `\{[^}]*\}`. A constraint holding a quantifier is dropped in one piece, and a path already compiled to `(?P<id>…)` loses the group rather than contributing `p_id`. | `leads/{id:\d+}` → `leads`                                           |
| 4    | Remaining non-alphanumerics become `_`, then the result is trimmed of leading and trailing `_`. An empty result becomes the literal `route`.                                                                                                                    | `leads/{id}` → `leads`; `/` → `route`                                |
| 5    | If the route's verbs are anything other than exactly `POST`, the lowercased verb list is appended.                                                                                                                                                              | `DELETE leads/{id}` → `leads_delete`; `PUT leads/{id}` → `leads_put` |

**Why step 5 exists.** `PUT leads/{id}` and `DELETE leads/{id}` both derive `leads`
from the path, and would then share one hook — so both callbacks would fire on one
submission instead of the later one replacing the earlier. Since a browser reaches
PUT and DELETE only through a `_method` field, the verb is the only thing
distinguishing them, so it belongs in the name. POST keeps the bare derived name,
because it is the overwhelmingly common case and `leads_store` reads better than
`leads_store_post`.

A collision at any point is **refused**, not merged: two form-handler routes
resolving to one action throw `RouteRegistrationException::duplicateAction` at
flush time, naming both paths.

The resolved name is written **back onto the route** as the `action` option, so
`route:list` and a view rendering the form's hidden field both report the string
WordPress will actually fire rather than deriving it a second time. Read it with
`AdminPage::fromRoute($route)->action()`, or `$adapter->actionFor($route)`.

#### How a submission is actually dispatched

The mechanism is not the obvious one, and the reason is ordering.

The hook name comes from the routes file, and the routes file is only read once a
WordPress action fires. So on the first submission of a process the sequence would
have to be: read the file → learn the action names → register
`admin_post_{action}` hooks → _then_ have WordPress fire the right one. It cannot
be, because WordPress resolves and fires `admin_post_{action}` in a single pass,
and by the time a listener on the generic `admin_post` action runs, that pass is
already under way.

So the name is not used to find the route — **the request is.** The provider hooks
generic `admin_post` and calls `dispatchCurrentAdminAction()`, which reads
`$_REQUEST['action']` and matches it against the routes already published in this
same request. A no-op when nothing matches, which matters: WordPress fires
`admin_post` for its own internal handlers too, and an unmatched action is not this
plugin's business — returning without sending lets WordPress continue to whoever
owns it.

Three consequences:

- **The routes file is read and flushed at priority 5**, ahead of the default 10,
  on `admin_menu`, `template_redirect` and generic `admin_post`. Loading is
  idempotent per file and `flush()` is guarded, so the repeats cost nothing.
- **A route written as `leads/{id}/delete` never has its placeholder filled from a
  URL.** A form submits to `admin-post.php?action=…` regardless of the original
  path, so the parameter is read from `$_REQUEST` — the form field of the same name
  — and only when it is scalar. A hidden input is how it arrives.
- **`_method` is honoured**, by the request itself. A browser cannot send `PUT`, so
  the form posts `POST` with `<input type="hidden" name="_method" value="DELETE">`,
  and the route matching a `DELETE` declaration is reached.

Form input arrives as a `Framework\Http\Request`; `$request->input('name')` reads
it, and validation lives in a `FormRequest` subclass.

#### What a web action may return

An admin screen and a form handler both return their response — neither exits, so
WordPress can render its chrome around a screen and can finish the request around a
redirect.

| Returned            | Outcome                                                                                                                                                            |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `ResponseInterface` | Sent as-is.                                                                                                                                                        |
| `string`            | Wrapped in an HTML response.                                                                                                                                       |
| `null`              | **Refused**, naming the route: a controller that returns nothing has produced no page, and an empty 200 looks like a broken plugin rather than a missing `return`. |
| an array            | **Refused**, naming the route. An array is the REST idiom; sent here it would emit the literal text `Array`.                                                       |
| `WP_REST_Response`  | **Refused**, naming the route. Only WordPress's REST server renders one; through this path it emits nothing at all.                                                |

A request that reaches a route on the wrong verb gets a **405 with an `Allow`
header**, not a 404 — the path exists, so saying "not found" would send a developer
looking for a route that is already there.

### Route Grammar and Specificity

Both domains read placeholders with **the same parser**
(`Framework\Routing\RouteCompiler`), which is the reason a route cannot resolve in
one file and 404 in the other. That is a real guarantee and not merely tidiness:
three callers used to carry their own regex for the same grammar and disagreed
about `{id:}` — the route dispatched while the binder reported no parameters, so
the argument was silently dropped.

| Written                 | Meaning                                                                                                                   |
| ----------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| `leads/{id}`            | One path segment, unconstrained. Compiles to `(?P<id>[^/]+)`.                                                             |
| `leads/{id:\d+}`        | One segment matching the constraint. The constraint **is** a regex and is left as written.                                |
| `leads/{id:[0-9]{1,3}}` | A constraint containing braces, a class and a quantifier — handled by the scanner, not by a string replace.               |
| `leads/{id:((\d+))}`    | Nested groups are fine: parens inside a character class are literal to PCRE, and the scanner knows it.                    |
| `leads/{id:}`           | An **empty** constraint is read as _no_ constraint, not as an empty group. It compiles to `(?P<id>[^/]+)`.                |
| `leads/(?P<id>\d+)`     | The compiled spelling. Accepted wherever a path is read, because a REST route's path is in this state after registration. |

Everything that is **not** a placeholder is `preg_quote`d. WordPress compiles the
registered path as `@^<route>$@i`, so without that a literal `.` would match any
character — `file.json` and `fileXjson` both dispatching — and a stray `(`, `[` or
`+` would corrupt the pattern so `preg_match` fails and the route is silently dead.

#### Routes are ordered most-specific-first

WordPress tries every registered pattern in insertion order and takes the first that
matches. So `leads/{id}` registered before `leads/latest` would dispatch `show`
with `id="latest"` — a silent wrong-action dispatch rather than a 404, which is the
worst of the three possible outcomes. Both adapters therefore sort by
`RouteCompiler::sortBySpecificity()` before handing anything to WordPress:

1. **More literal segments first.** `/leads/latest` scores 2 and `/leads/{id}`
   scores 1, so the literal route is matched first.
2. **Then more constrained placeholders first**, breaking the tie — a
   `{id:\d+}` sorts ahead of a bare `{id}`.

Routes identical on both keys keep their relative order only by accident of the
sort, which is exactly why the duplicate guard refuses a same-method-same-path pair
outright rather than relying on the ordering to save it.

### Verb Coverage

`get`, `post`, `put`, `patch`, `delete` are the everyday spellings. Three more
exist, and the supported set is a stated constant rather than whatever the adapter
happens to accept:

| Call                                               | Registers                                                                      |
| -------------------------------------------------- | ------------------------------------------------------------------------------ |
| `Route::get/post/put/patch/delete($path, $action)` | One verb.                                                                      |
| `Route::match(['GET', 'POST'], $path, $action)`    | One route per verb listed.                                                     |
| `Route::any($path, $action)`                       | `RouteRegistrar::SUPPORTED_METHODS` — `GET`, `POST`, `PUT`, `PATCH`, `DELETE`. |

Anything outside `SUPPORTED_METHODS` is refused with
`RouteRegistrationException::unsupportedMethod` **when the route is declared**, not
at dispatch. A verb that reaches WordPress unregistered is a route that exists in
your file and nowhere else.

`Route::controller()` declares several actions of one controller in a single call,
and it is what the `#[Route]` attribute and `make:controller` emit:

```php
Route::controller(PostController::class, [
    'index'  => 'GET',
    'store'  => 'POST',
    'delete' => 'DELETE',
], 'posts');
```

The action named `index` maps to the **base path itself**; every other action is
appended. So the above registers `posts`, `posts/store` and `posts/delete`.

### Route Groups

`Route::group()` shares attributes across every route declared inside its
callback. Three keys are supported, and they **compose across nesting**:

```php
Route::group(['as' => 'leads.', 'prefix' => 'leads', 'middleware' => [Authenticate::class]], function (): void {
    Route::get('all', [LeadController::class, 'index'])->name('index');   // name: leads.index
    Route::get('new', [LeadController::class, 'create'])->name('create'); // name: leads.create
});
```

| Key          | Effect                                                                                                                                                |
| ------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| `as`         | A **name** prefix. `['as' => 'leads.']` plus `->name('index')` gives `leads.index`.                                                                   |
| `prefix`     | A **path** prefix, joined the way a URI joins: `['prefix' => 'leads']` plus `Route::get('all', ...)` registers the screen `admin.php?page=leads/all`. |
| `middleware` | Middleware applied to every route in the group.                                                                                                       |

`as` and `prefix` are independent — `['prefix' => 'leads', 'as' => 'leads.']` is
the ordinary pairing, but grouping _paths_ under a name prefix, or the reverse, is
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
> restored on the way out, so a route declared _after_ the group does not inherit
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

| Alias        | Class                       | What it does                                                                                                                                                                                 |
| ------------ | --------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `capability` | `CheckCapability`           | Requires the capability named as a parameter. All comma-separated capabilities must be held. **Fails closed** — an empty parameter or a missing `current_user_can` denies with 403.          |
| `nonce`      | `VerifyNonce`               | Verifies `_wpnonce` against the named action. An optional second parameter renames the field. **An empty action is refused**, because WordPress's `-1` is a specific action, not a wildcard. |
| `trim`       | `TrimStrings`               | Trims leading/trailing whitespace from string input, at any nesting depth.                                                                                                                   |
| `null`       | `ConvertEmptyStringsToNull` | Turns `''` into `null`. `'0'` is left alone.                                                                                                                                                 |
| `sanitize`   | `SanitizeInput`             | `wp_unslash()` then `sanitize_text_field()` — the WordPress baseline. Collapses newlines, so do not enable it on a field that needs them.                                                    |
| `auth`       | `Authenticate`              | Requires a logged-in user.                                                                                                                                                                   |
| `can`        | `Authorize`                 | Capability check that **fails open** with a logged warning — prefer `capability` for anything that guards data.                                                                              |
| `throttle`   | `ThrottleRequests`          | Rate-limits the route. Parameterized as `throttle:maxAttempts,decayMinutes` — `'throttle:60,1'` is 60 requests per minute. Refuses over-limit requests with a 429 carrying `Retry-After`.    |

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
$aliases->aliasMany([
    'lead-access' => \App\Http\Middleware\RequireLeadRead::class,
    'lead-write'  => \App\Http\Middleware\RequireLeadWrite::class,
]);
```

Four rules about aliases, all of which exist to keep an alias from silently
substituting a class:

| Rule                                                                                                                 | Why                                                                                                                                                                                          |
| -------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **A loadable class name always wins.** Resolution checks `class_exists()`/`interface_exists()` before the alias map. | A project may define its own `CheckCapability`; a registry that rewrote the name would quietly substitute the framework's.                                                                   |
| **A name containing `:` is refused.**                                                                                | The colon separates a middleware from its parameters, so an alias holding one could never be addressed — `'foo:bar'` would always parse as the alias `foo` with parameter `bar`.             |
| **An unknown name resolves to itself.**                                                                              | It fails later at dispatch, naming the entry. Reporting it at registration would mean naming no route and being unable to tell a typo from a project's own un-aliased middleware.            |
| **`alias()` silently replaces.**                                                                                     | A project overriding `'capability'` to point at its own class is legitimate, and refusing would make the defaults unoverridable. Same contract as the container's `instance()` and `bind()`. |

The registry is a singleton, so an alias added at boot is visible to every route
dispatched later in the process.

Two stack-building methods, and the difference matters because one replaces and one
appends:

```php
$pipeline->send($request)->through(['capability:manage_options'])->then($destination);
$pipeline->send($request)->through([...])->pipe(TrimStrings::class)->then($destination);
```

#### Rate limiting (`throttle`)

```php
->middleware('throttle:5,10')   // five submissions per ten minutes
```

The counter is keyed on **the authenticated user's id when there is one, and the
client's IP otherwise**, namespaced by the route's path — so two routes never
share an allowance and a paginating client does not get a fresh one per page.

`X-Forwarded-For` is deliberately **not** trusted: it is client-supplied, and
honouring it lets any caller pick a new counter per request, which does not weaken
the limit so much as remove it. A project behind a trusted proxy should subclass
`ThrottleRequests` and override `clientKey()`, having first confirmed the proxy
strips the header from inbound requests.

Counting is a single `INSERT … ON DUPLICATE KEY UPDATE` against the
`{prefix}mini_rate_limits` table, so two simultaneous requests cannot both read
the same count and both decide they are allowed — the race a
read-then-write limiter (transients, `get_option`) cannot avoid. **Run the
migration that creates that table**; without it every throttled route refuses
with a 503 rather than silently allowing everything.

The middleware **fails closed**: if the limiter cannot run, the request is
refused. A guard that cannot run has not guarded anything, and a limiter that
stops limiting is invisible precisely when traffic is high enough to need it.

### The `route()` Helper (Intelligent URL Generation)

`route('name', $parameters)` turns a route name into the URL WordPress actually
serves it at. **Which WordPress function is used is decided by the route's
surface**, not by the caller — so a link and the screen it points at cannot
disagree:

| Route surface  | Generated URL                          | WordPress function |
| -------------- | -------------------------------------- | ------------------ |
| Admin screen   | `admin.php?page=leads`                 | `admin_url()`      |
| Form handler   | `admin-post.php?action=leads_store`    | `admin_url()`      |
| REST endpoint  | `/wp-json/wp-mini-laravel/v1/leads/42` | `rest_url()`       |
| Front-end page | `thank-you` (plus a query string)      | `home_url()`       |

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

The query string is built by `rawurlencode`ing **each key and value separately** and
joining the pairs with `&` — deliberately not `http_build_query()`, which encodes a
space as `+` under its default encoding. That is wrong in a path context and
differs from what the path segments get, so the generator uses one encoding rule
throughout rather than two.

Three consequences of the placeholder rules:

- **A parameter the path cannot consume is not silently dropped.** On a front-end
  page it becomes a query string. On a form handler it is a mistake — an
  `admin_post_{action}` name cannot carry a value, which is why the derivation
  drops placeholders — so it is not tolerated either.
- **A non-scalar value throws.** An array would stringify to `Array` and produce a
  URL that looks plausible and goes nowhere.
- **A placeholder with no value throws**, naming the route and the parameter. See
  the two mistakes below.

Two mistakes are told apart, with different messages:

- **Unknown name** — throws `InvalidArgumentException` and _lists the names that
  do exist_, so a typo is a glance rather than a hunt. The list is **capped at 20
  names**, with `(and N more)` appended, so a large project does not get a wall of
  names with the one missing fact buried in it.
- **Missing placeholder** — `leads/{id}` with no `id` throws `RuntimeException`.
  It is not quietly rendered as `/leads/`, which is a valid-looking URL that
  resolves to nothing.

A name that resolves to _nothing at all registered_ gets its own message rather
than the list, because "no routes are registered at all yet" is a different
problem from a typo — and it is the answer you get when `route()` is called before
any hook has fired.

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

`has()` answers `false` for a name that is ambiguous as well as for one that does
not exist, so it never reports a route that `route()` would refuse to build.

When you hold a `Route` rather than a name — a template part handed one, or an
unnamed route, which has no name to look up — ask the generator directly:

```php
$urls->page($route, ['order' => 7]);   // the front-end page URL for this route
```

`page()` is the one surface-specific builder exposed this way, because a front-end
page is the one surface where calling it without the route's name is the ordinary
case. The other three are reachable by name.

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
REST router — so `RouteLoader` aims the facade at the router it is loading _for_,
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

| Endpoint                                     | Response                                                             | Guard                    |
| -------------------------------------------- | -------------------------------------------------------------------- | ------------------------ |
| `GET /wp-json/wp-mini-laravel/v1/ping`       | `{"pong":true}`                                                      | none — deliberately open |
| `GET /wp-json/wp-mini-laravel/v1/leads`      | `{"data":[{...},{...}]}`                                             | `RequireLeadRead`        |
| `GET /wp-json/wp-mini-laravel/v1/leads/{id}` | `{"data":{...}}`                                                     | `RequireLeadRead`        |
| …unknown `{id}`                              | `{"error":"not_found","message":"No lead with id 99."}` with **404** | `RequireLeadRead`        |

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
> 403s for _every_ caller including administrators. It reads as a permissions bug
> in the wrong file. The parameterized syntax described
> [above](#parameterized-middleware) belongs to `routes/web.php`, where the
> pipeline owns the request and can pass arguments to `handle()`.

Routes can also be declared on a controller with the `#[Route]` attribute, which
`Framework\Routing\Controller::registerRoutes()` reflects over; `make:controller`
generates the attribute form. The attribute takes the verb, the path, the
middleware and a permission callback:

```php
#[Route('GET', 'posts', middleware: [RequireCapability::class])]
#[Route('DELETE', 'posts/{id:\d+}', permissionCallback: 'Checks@canDelete')]
```

It is repeatable, so one method may carry several. A route declared this way goes
through exactly the same group prefix and group middleware handling as one written
by hand — `registerRoutes()` calls `RouteRegistrar::controllerAction()`, the same
seam `Route::controller()` uses.

#### The `permission_callback` seam

WordPress offers a REST route exactly one authorization hook, and it is a yes/no
check: `permission_callback`. Middleware on a REST route runs **there**, not in the
HTTP pipeline, and that is why the two middleware contracts are separate rather
than merged.

Every middleware runs in registration order and the first to return a `WP_Error`
wins. Then:

| Returned      | Meaning                                                                                                                                                                                                                   |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `WP_Error`    | Denied, and the error's own status and message survive unchanged.                                                                                                                                                         |
| `null`        | **Denied.** A middleware returns `null` to mean "no opinion" and WordPress reads it as a refusal — but the adapter has already returned by then, so an explicit `permissionCallback` returning `null` denies the request. |
| `false`       | Denied.                                                                                                                                                                                                                   |
| anything else | **A contract violation** — 500, naming the class and what it returned. A truthy `bool` or a string would otherwise be indistinguishable from the final "allowed" and would turn a protected route into a public one.      |

Two failure rules that apply to every path through this seam:

- **Authorization fails closed.** A middleware that _throws_ — a database blip, a
  null dereference, a third-party call — is converted into a denial rather than
  escaping. WordPress calls the permission callback with no error handling of its
  own, so a throwable there would be an unhandled fatal that any unauthenticated
  client could trigger on demand. An authorization check that could not run has
  authorized nothing.
- **A permission callback is named by string**, in one of the three forms the
  container understands: a plain function name, a static method
  (`Checks::canDelete`), or an instance method on a resolvable class
  (`Checks@canDelete`). A **bare method name** such as `canDelete` is _not_ one of
  them — nothing ties it to the route's controller, so it is reflected as a plain
  function, not found, and the route denies. Because that is indistinguishable from
  a real authorization failure, the error message names the callback and carries
  the underlying reason, so a wiring bug can be told apart from a locked-down route.

#### `WP_Error` codes

The `code` a client switches on is `{app.prefix}` plus one of these, so it is
stable across installs and rebrands:

| Reason                       | Status                                                              |
| ---------------------------- | ------------------------------------------------------------------- |
| `unresolvable_controller`    | 500                                                                 |
| `controller_failed`          | 500                                                                 |
| `no_handler`                 | 500                                                                 |
| `invalid_request`            | 400                                                                 |
| `route_failed`               | 500, or 400 for a binding failure                                   |
| `middleware_failed`          | `rest_authorization_required_code()` — 401 anonymous, 403 logged in |
| `middleware_contract`        | 500                                                                 |
| `permission_callback_failed` | `rest_authorization_required_code()`                                |

The adapter builds these through the central `Handler`, so the _decisions_ — status,
envelope, whether the message is safe to show, and the transaction rollback — come
from one place while the _shape_ stays a `WP_Error`. That is the same split the two
middleware contracts make, for the same reason: WordPress's REST server serializes
whatever a callback returns and knows nothing about this framework's responses, so a
`JsonResponse` returned from a REST callback would emit nothing at all.

`Handler::toRestError()` is that seam, and it is public so a project can reach the
same decisions without reaching the same shape.

### Parameter Binding

One class binds request values onto a controller action for **both** domains —
`Framework\Routing\ParameterBinder`. The two differ in exactly two seams, and both
are injected: where the values come from (`ParameterSource` — a `WP_REST_Request`
or a `Framework\Http\Request`), and whether the request object itself is
injectable.

That sharing is not tidiness. Everything after the source is the same set of sharp
decisions, and a fix to any of them fixes both domains rather than half of each.

#### A route placeholder beats the body

The rule the whole class exists to enforce. WordPress ranks request sources JSON,
then body, then query, then URL **last** — so a POST body carrying `{"id": 999}`
would fill an `$id` parameter that the URL matched as `123`, letting a client
address one record and act on another. An ownership check written against the URL
would then be bypassable.

Two mechanisms close it, on the two domains:

- **REST** — `pinRouteParameters()` writes the URL-matched values back through
  `set_param()`, putting them at the front of WordPress's own priority order, so the
  matched value is what the action sees however it reads the request.
- **Web** — the binder reads route parameters **before** the source, and prefers
  them by name when the loop reaches that parameter.

#### A `bool` parameter never receives the string `"false"`

`{"published": "false"}` arriving at a `bool $published` parameter is coerced by PHP
to `true` — the non-empty string is truthy — and a draft silently publishes. So
every accepted spelling is converted before reflection sees it:

| Input                  | Result                                                        |
| ---------------------- | ------------------------------------------------------------- |
| `'true'`, `'1'`        | `true`                                                        |
| `'false'`, `'0'`, `''` | `false`                                                       |
| `1`, `0`               | `true`, `false`                                               |
| anything else          | passed through unchanged, and refused by the type check below |

Matching is case-insensitive, because `?published=False` from a hand-built query
string is as likely as the lowercase form.

The type check runs first and is its own table: an `int` parameter accepts an
integer or an all-digits string, a `float` accepts an int, a float or a numeric
string, a `bool` accepts any spelling above, an `array` accepts only an array — a
PHP array cannot arrive from a URL segment, so it can only be satisfied by a
structured body value. A value that satisfies nothing raises
`RouteResolutionException::unexpectedArgument`, naming the parameter, the value, the
controller and the action. A parameter no value was supplied for is **left out
entirely**, so the container applies the parameter's own default, or reports a
required scalar as unresolvable rather than passing `null` in and raising a
`TypeError` inside the controller.

#### A `FormRequest` parameter is a gate in front of the action

Type-hint a `FormRequest` subclass and the action is **never entered** when
validation fails — the binder returns the request's own failure response in its
place. That is the one place a controller's contract is enforced without the
author's cooperation, and it is why the check lives in the binder: the type-hint is
the declaration, and the binder is the only code that reads it before the call.

Two details worth knowing:

- **Binding continues after a failure.** Every parameter is still bound, so a
  parameter whose type no request value can satisfy is still reported — the two
  defects stay distinguishable. A rejected form is something the user can fix; an
  unbindable parameter is something only the route author can. Returning early
  would hide the second behind the first, and would make binding depend on where in
  the signature the form request sits.
- **The response shape is chosen by the client, not the route.** A request that
  `expectsJson()` gets a `422` with the errors. A browser form post gets a redirect
  back to the form with the errors and old input flashed. The route's _file_ is not
  the test: an admin screen whose form is submitted by `fetch()` is an HTML route
  that wants a JSON error.

The distinction between "a REST dispatch" and "a web dispatch" is carried by a
marker the REST adapter primes around the call, not by inspecting the request —
both domains build byte-identical `Request` objects, so nothing observable tells
them apart.

### `route:list`

```bash
wp wp_mini route:list
```

Every registered route in both domains, in one table: the domain, the verb, the
WordPress **hook** it publishes on, the `Controller@action`, and the middleware.
With no routes it says so and names the two files to look in.

Three things about it are deliberate:

- **It loads both routes files itself.** WP-CLI fires none of `rest_api_init`,
  `admin_menu`, `admin_post` or `template_redirect`, so both routers would be empty
  and the true answer invisible. Listing nothing would be a _wrong_ answer presented
  confidently. It goes through `RouteLoader`, the same entry point the providers
  use, so it cannot disagree with them about what loading a routes file means — and
  the load is idempotent per file, so a process where a hook already fired lists
  what that load collected rather than doubling it.
- **A routes file that fails to load is a warning, not an abort.** The table still
  prints. A broken file usually takes _some_ of the routes with it, and seeing the
  ones that did register is how the mistake gets found.
- **The URI column puts the hook first**, with the URL as a parenthetical. The hook
  is the string a developer greps for in WordPress's source when a route does not
  fire, and WordPress reaches both web surfaces through the same `admin-post.php`
  and `admin-menu.php` entry points — so the URL's distinguishing part is its query
  string, which sits exactly where truncation cuts.

The **Domain** column is what makes the table answerable. `leads` may be an admin
screen, a form action or a REST endpoint: three different URLs, three different
hooks. The URI shows which one, but only because the domain says how to read the
URI.

### What the Routing Layer Binds

`App\Providers\RouteServiceProvider` installs these, and the string keys are not
an accident:

| Key                                  | Binding                  | Why                                                                                                                                                                                                                                                                    |
| ------------------------------------ | ------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `RouteFacade::ACCESSOR` (`'router'`) | `null`                   | Bound rather than omitted, because the facade distinguishes "unbound" from "bound to nothing". Nothing resolves it directly — `RouteLoader` aims the facade at a registrar around each routes file.                                                                    |
| `router.api`                         | `RestApiRouterAdapter`   | **String keys, because two adapters share `RouterInterface`** and a type-hint on it would have no single correct answer. A silent choice between them is a route registered into the wrong domain.                                                                     |
| `router.web`                         | `WordPressRouterAdapter` | As above.                                                                                                                                                                                                                                                              |
| `'url'`                              | `UrlGenerator`           | What the `route()` helper resolves.                                                                                                                                                                                                                                    |
| `NamedRoutes`                        | singleton                | One registry, so `route()` can find a name whichever domain declared it. Two registries would mean a name was only ever findable in one domain, silently.                                                                                                              |
| both adapters                        | singletons               | **Load-bearing.** A routes file is `require`d and its calls land on whichever router the facade was aimed at. If the adapter were re-resolved at flush time it would be a _different object_ holding an empty table, and every route would vanish with nothing raised. |

Most callers should type-hint the concrete adapter class rather than either string
key — `RouteListCommand`, for instance, takes both adapters and the loader by
constructor injection.

Two consequences of the wiring, both covered above: the web hooks run at
**priority 5**, and `routes/api.php` is loaded by the framework's own
`RouteServiceProvider` (which the app provider extends) — it must not be loaded a
second time, or every endpoint registers twice into one request and WordPress takes
whichever it matches first.

> **A note on the zone boundary.** `framework/Routing/` imports nothing from `App\`
> or `Core\` — it is clean, and that is what lets the whole routing layer be tested
> without a domain. Three classes in `framework/Http/` do reach into `Core\`, and
> each is deliberate: `Handler` maps `DomainValidationException` to a 422 and rolls
> back through `TransactionManagerContract`, which is the _only_ seam that knows both
> zones and the reason the domain's exception carries no status; and
> `HttpServiceProvider` resolves the transaction manager **conditionally**, so a
> project with no database still gets a working error path. The rule being kept is
> the one in [The Three-Zone Architecture](#the-three-zone-architecture) — `core/`
> never depends on `app/` — and none of these three inverts it.

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
path says _where the file goes_; the flag says _what it is_.

The two surfaces share no base class, no request type and no return type, so
they are different stubs rather than one file with a namespace swapped in. This
table is the contract every stub is held to:

| Flag                         | Stub                            | Extends                            | `final` | Takes             | Returns             | Actions                                                         |
| ---------------------------- | ------------------------------- | ---------------------------------- | ------- | ----------------- | ------------------- | --------------------------------------------------------------- |
| _(none)_                     | `controller.stub`               | `Framework\Routing\Controller`     | no      | —                 | —                   | _(none)_                                                        |
| `--resource`                 | `controller.resource.stub`      | _(nothing)_                        | yes     | `Request`         | `ResponseInterface` | `index`, `create`, `store`, `show`, `edit`, `update`, `destroy` |
| `--api`                      | `controller.api.stub`           | `Framework\Routing\Controller`     | no      | `WP_REST_Request` | `WP_REST_Response`  | `index`, `store`, `show`, `update`, `destroy`                   |
| `--invokable`                | `controller.api.invokable.stub` | `Framework\Routing\Controller`     | no      | `WP_REST_Request` | `WP_REST_Response`  | `__invoke`                                                      |
| `--invokable` under `Admin/` | `controller.invokable.stub`     | _(nothing)_                        | no      | `Request`         | `ResponseInterface` | `__invoke`                                                      |

Only `--resource` is `final`, and the split is not arbitrary. A generated class is
`final` when it is **finished** — the web `--resource` stub is a complete set of
screens a developer fills in, so nothing should extend it. The rows that extend
`Framework\Routing\Controller` are open, because that base class is a shared
surface a project may want to build a house base class on top of.

**The two request types are not interchangeable, and neither are the two return
types.** A `Request` is the framework's own input object, read with
`$request->input('name')` and validatable through a `FormRequest`. A
`WP_REST_Request` is WordPress's, read with `$request->get_param('name')`. A web
action returns a `Framework\Http\Contracts\ResponseInterface`; a REST action
returns a `WP_REST_Response` that WordPress's REST server renders. What a web
action may and may not return is a closed list — see
[What a web action may return](#what-a-web-action-may-return); a
`WP_REST_Response` from a web route is **refused**, naming the route.

The three shape flags are mutually exclusive; asking for two at once is refused
before anything is written. `--invokable` is the one shape with no surface flag
of its own, so its surface comes from the namespace: under `Admin/` it is a web
controller, anywhere else a REST one. The match is on the **first** namespace
segment, case-sensitively: `Admin\Reports\CompanyController` is a web
controller, `Reports\CompanyController` is not. There is no rule that says a
namespace called `Reports` is a screen.

#### What a `--resource` controller generates

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

The three form handlers each flash a different message — `Created.`, `Updated.`,
`Deleted.` — and all three redirect to the same `<name>.index` target.

**Nothing is injected and nothing is stubbed.** An injected dependency is one
more thing to delete before the feature's own logic can be written, so the stub
has no constructor at all. `view()` and `redirect()` resolve through the
container at call time, which is what lets a scaffold with no constructor stay
honest: the response a generated action returns is the same one an injected
renderer would have produced.

#### What an `--api` controller generates

An `--api` controller declares `#[Route]` attributes on its five actions. Those
attributes describe the routes, but **they do not register themselves**: nothing
scans `app/Http/Controllers` at boot looking for controllers carrying them. The
class is inert until a routes file names it.

There are two ways to name it, and **they produce different route tables** — this
is the one trap in the shape, so it is worth reading twice.

**Attribute-driven** — mount the controller so `registerRoutes()` reflects over
its methods, which is what the `#[Route]` attributes are for:

```php
Route::group(['prefix' => ''], function (): void {
    (new CompanyController())->registerRoutes(Route::target());
});
```

Every route then comes from an attribute, so the paths are the ones written in the
class: `Company` (index, store) and `Company/(?P<id>\d+)` (show, update, destroy).
The identifier segment is written in its **compiled** form rather than the
`{id:\d+}` shorthand used in hand-written routes files; both are accepted wherever
a path is read, since
[a route's path is in compiled form after registration](#route-grammar-and-specificity).

**Imperative** — declare the actions in the routes file instead:

```php
Route::controller(CompanyController::class, [
    'index'   => 'GET',
    'store'   => 'POST',
    'show'    => 'GET',
    'update'  => 'PUT',
    'destroy' => 'DELETE',
], 'Company');
```

> **`Route::controller()` ignores the `#[Route]` attributes.** It builds its own
> paths by appending each action name to the base, so the same controller mounted
> this way answers `Company`, `Company/store`, `Company/show`, `Company/update`
> and `Company/destroy` — not `Company/(?P<id>\d+)`. Pick one mechanism per
> controller, or you will have two overlapping tables registering from one class.

The action named `index` maps to the base path itself in both mechanisms; every
other action is appended in the imperative one.

The five actions return through the inherited helpers: `respond()` for `index`
and `show` (200), `created()` for `store` (201), and `noContent()` for `destroy`
(204). `update` returns `respond()`.

> **`make:controller --api` prints the imperative mount.** Its post-write hint
> reads `Mount it with Route::controller(CompanyController::class, [...], 'Company')`
> — which is correct for the *route names and verbs* but re-derives the paths, so
> the `{id}` segments in the generated attributes are not the ones that end up
> registered. If you generated the file for its attributes, mount it via
> `registerRoutes()`; the hint names the other mechanism.

#### The mount the command prints

Once the file is written the command prints the mount for the shape it actually
generated — the stub, not the flag, decides which line is printed, since
`--resource` under `Admin/` and `--invokable` anywhere have surfaces the flag
alone does not fix:

| Shape generated                  | Printed hint                                                        |
| -------------------------------- | ------------------------------------------------------------------- |
| bare (`controller.stub`)         | It declares no routes yet; add `#[Route]` attributes and mount it.   |
| `--api`                          | `Route::controller(Foo::class, [...], 'Foo')` in `routes/api.php`.   |
| `--invokable` (REST)             | `Route::post('Foo', Foo::class)` in `routes/api.php`.                |
| `--resource`                     | `Route::adminPage(...)` / `Route::adminAction(...)` in `routes/web.php`. |
| `--invokable` under `Admin/`     | `Route::adminPage('…', Foo::class)` in `routes/web.php`.             |

#### Placeholders a stub may use

Each stub is rendered by replacing `{{ … }}` placeholders, all derived from the
**class name** the generator stripped of its `Controller` suffix, its namespace
segments and its `.php` extension — so `Admin/CompanyController` renders
`Company`, not `Admin/Company`:

| Placeholder     | For `CompanyController` | Used by                                | Casing                                            |
| --------------- | ----------------------- | -------------------------------------- | ------------------------------------------------- |
| `{{ namespace }}` | `App\Http\Controllers\Admin` | every stub                       | as declared                                       |
| `{{ class }}`     | `CompanyController`     | every stub                             | as declared                                       |
| `{{ route }}`     | `Company`               | the two REST stubs                     | class spelling, suffix stripped                   |
| `{{ name }}`      | `company`               | the web stubs' redirect target         | lower-cased first letter                          |
| `{{ view }}`      | `company`               | the web stubs' view directory          | lower-cased                                       |
| `{{ items }}`     | `companies`             | the web stub's `index` view data (the collection) | lower-cased, naively pluralized       |
| `{{ item }}`      | `company`               | the web stub's `create`/`show`/`edit` view data (the single record) | lower-cased first letter |

`{{ route }}` and `{{ name }}` deliberately disagree about case: a route path is
a noun in a URL and a route **name** is what `redirect()->route()` matches
against. `{{ view }}` and `{{ item }}` disagree with both, because a view is a
file path. Each is spelled the way its own surface conventionally spells it.
The plural is a naive rule (`-y` → `-ies`, sibilants → `-es`, else `+ s`), not an
inflector — it names a **local variable** in a generated action that the
developer renames the moment they write the query behind it.

#### Redirect names, and the one that throws

The web stubs name their redirect target `<name>.index` — `company.index` for
`CompanyController` — so a generated `store()` only works once you have given the
`index` route that name with `->name('company.index')`. A name that does not match
throws when the redirect is built, naming the routes that do exist.

#### Overriding a stub, and the refusals

Each shape is a separate file in `framework/stubs/`, so a project can override
one without touching the others by copying it into its own `stubs/` directory.
They are resolved through `StubLocator`, which prefers the project's copy and
falls back to the framework's — a project that edits one command's stub and not
another's has two copies answering the same name, which is what `StubLocator::source()`
exists to make answerable.

`--resource`, `--api` and `--invokable` together are refused rather than
resolved: they name different files, and silently picking one would report
success for a controller nobody asked for. The refusal happens before anything
is written, so a rejected run leaves no file behind. The count is what is
checked, not a table of pairs — a `match` over combinations would have to
enumerate every one, and the pair it forgot would fall through to the default
stub and report the wrong shape as a success.

Generation into an occupied path is refused by the base generator rather than
overwritten, and a sub-namespace argument (`Admin/CompanyController`) places the
file and its matching namespace without changing the placeholders above.

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
  `mini` downloads `wp-cli.phar` once and reuses it from then on. A machine that
  already has WP-CLI never triggers a download.

  The copy is cached in a namespaced directory under the system temporary
  directory — `sys_get_temp_dir() . '/wp-mini-laravel-cli-cache'`, so
  `%TEMP%\wp-mini-laravel-cli-cache` on Windows and `/tmp/...` elsewhere. It is
  deliberately **not** inside the plugin. A running process that has read the
  phar holds a share lock on it under Windows, and WordPress cannot recursively
  delete a locked file — which is exactly how a cached phar used to turn into
  `Could not fully remove the plugin wp-mini-laravel/wp-mini-laravel.php` during
  an uninstall or update. A cache is regenerable machine-specific state, not
  plugin data, so it has no business in the directory WordPress has to be able
  to delete outright. Run `php mini --help` to see the resolved path.

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
script — then the cached download in the system temporary directory. Both cURL
and PHP's stream wrapper are tried for the download, because the two read their
TLS trust anchors from different ini settings and a machine can have a working
HTTPS stack in one and not the other. The download is written to a `.part` file
and renamed into place, so an interrupted transfer can never be cached as a
truncated phar.

`tests/manual/verify-mini-entrypoint.php` checks all of this against a real
WordPress — including the case that matters most, that a prefix the plugin has
not registered does _not_ silently succeed.

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
WP-CLI being loaded merely to be _declared_, not to be run. `App\Console\Command`
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

### Exception Handling

Both route adapters wrap their dispatch in `try / catch (\Throwable)` and hand
whatever they catch to `Framework\Http\Exceptions\Handler`. Nothing reaches
WordPress as a fatal, and every failure becomes a response shaped for the client —
JSON for a request that asked for JSON, an HTML page otherwise.

The handler does three things, **in this order**, and the order is load-bearing:

1. **Roll back.** If a transaction is open it is abandoned first. Rendering can
   itself throw — a broken view, an unwritable log — and `$wpdb` closes an open
   transaction by _committing_ it, so "left open across a render failure" means
   half the work becomes durable, which is exactly what the transaction existed to
   prevent.
2. **Report.** Every throwable is logged, including ones whose message is withheld
   from the client. Withholding is about the client, not about losing the detail.
3. **Render.** The status, the envelope and whether the message is safe.

```php
throw new NotFoundHttpException('No such lead.');        // 404
throw new TooManyRequestsHttpException(30);              // 429 + Retry-After: 30
throw new ValidationHttpException('Invalid.', $errors);  // 422 + field errors
```

The JSON envelope is stable — `{error, message}`, plus `errors` and `code` when
present — and `error` is a slug derived from the **status**, not the exception
class, so a client switching on it does not break when the framework adds a
subclass.

**The domain never names a status code.** `Core\` may not depend on `Framework\`,
so a genuine invariant violation raises
`Core\Shared\Exceptions\DomainValidationException` — which extends `\DomainException`
and carries no HTTP knowledge at all. The handler is the only seam that knows both
zones, and maps it to a 422 there:

```php
// core/ — no status, no headers, no HTTP
throw DomainValidationException::field('email', 'That address is not valid.');
```

Override `Handler::mapDomainException()` to change that policy — a JSON API and an
HTML admin may want different statuses for the same violation, and that one method
is the only place either answer belongs.

**Server-fault messages are withheld in production.** An uncaught `RuntimeException`
from a repository leaks the schema — `SQLSTATE[HY000] … MySQL server has gone away`
names the engine, the driver and the failure mode, to whoever asked. So a throwable
the handler had to _wrap_ says `An unexpected error occurred.` unless `APP_DEBUG` is
on. A deliberate `HttpException` keeps its own message at any status, because its
author wrote it to be read:

```php
throw new HttpException('The payment gateway is unavailable.', 503);  // shown as-is
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

Note what is _not_ in that snippet: no `$wpdb`, no `$wpdb->prefix`, no
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

| Columns                                                                                                                                                                                                                                                                                                                                                                         | Modifiers                                                       | Indexes                                                                              | Foreign keys                                                                                                                                                  |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------- | ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id()`, `string()`, `char()`, `text()`, `mediumText()`, `tinyText()`, `integer()`, `unsignedInteger()`, `unsignedBigInteger()`, `unsignedTinyInteger()`, `boolean()`, `datetime()`, `date()`, `time()`, `year()`, `timestamp()`, `decimal()`, `float()`, `double()`, `binary()`, `json()`, `enum()`, `set()`, `uuid()`, `ulid()`, `ipAddress()`, `macAddress()`, `timestamps()` | `nullable()`, `default()`, `useCurrent()`, `after()`, `first()` | `index()`, `indexColumn()`, `indexWith()`, `unique()`, `dropIndex()`, `dropUnique()` | `foreignId()`, `foreignUuid()`, `foreignUlid()`, `morphs()`, `nullableMorphs()`, `constrained()`, `cascadeOnDelete()`, `nullOnDelete()`, `restrictOnDelete()` |

Three of those are multi-column helpers rather than single types:

| Helper                      | Emits                                                                                                                                  |
| --------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| `morphs('subject')`         | `subject_type` varchar(191) + `subject_id` bigint unsigned, both `NOT NULL`, plus a composite index                                    |
| `nullableMorphs('subject')` | the same two columns, both nullable — `morphs(…)->nullable()` would reach only the second                                              |
| `softDeletes()`             | a nullable `deleted_at` datetime. Deliberately **not** indexed: the useful index is composite and depends on what the table filters by |
| `rememberToken()`           | a nullable `remember_token` varchar(100)                                                                                               |

**`json()` and `binary()` take no `default()`, and `enum()`/`set()` refuse one
too.** MySQL rejects a `DEFAULT` clause on a json or blob column outright, and
accepts one on an enum that is _outside_ the member list — storing the empty
member rather than refusing it, which is a schema that quietly allows a value
nothing meant to permit. All four are refused at build time with that said.

`enum()` and `set()` also check the things MySQL checks but reports badly: an
empty list, an empty member (indistinguishable from "nothing selected"), and the
255-byte ceiling across the whole definition. Values are escaped as SQL literals,
so a member containing a quote cannot break out of the list.

Changing an existing table — all of these are `Schema::table()`, and all of them
compile into the one `ALTER TABLE` the callback describes:

| What                         | Method                                                   |
| ---------------------------- | -------------------------------------------------------- |
| Add columns and indexes      | `string('slug')`, `index()`, `unique()`, …               |
| Drop a column                | `dropColumn('slug')` or `dropColumn(['a', 'b'])`         |
| Rename a column              | `renameColumn('body', 'content')`                        |
| Change a column's definition | `string('body', 500)->nullable()->change()`              |
| Move a column                | `string('title')->after('id')->change()`, or `->first()` |
| Drop an index                | `dropIndex(['name'])`, `dropUnique(['name'])`            |

`Schema::rename('notes', 'archive')` renames a _table_, and is the one alteration
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

> **Note on the two examples.** Raw `$wpdb` DDL for _schema_ changes is not
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

| Action                       | Fires                                                | Arguments                                                 |
| ---------------------------- | ---------------------------------------------------- | --------------------------------------------------------- |
| `{prefix}migration_starting` | immediately before the body runs                     | `string $identifier`, `string $direction`                 |
| `{prefix}migration_finished` | after the body returned, **only on success**         | `string $identifier`, `string $direction`                 |
| `{prefix}migration_failed`   | when the body threw, before the exception propagates | `string $identifier`, `string $direction`, `Throwable $e` |

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
deploy). `GET_LOCK()` has no such problem: MySQL binds it to the _connection_, so
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

## Database & Query Builder

The migration builder above describes **schema**. This section covers **data**:
`QueryBuilderInterface`, a fluent builder that reads and writes rows through
`$wpdb` without any repository writing SQL by hand.

It lives in `framework/Database/Query/`, is bound by
`Framework\Database\QueryServiceProvider` (listed in
[Configuration](#configuration)), and is resolved by the repositories that use
it — `WPFluentCompanyReadRepository`, `WPFluentCompanyWriteRepository` and
`LeadReadRepository`. A repository on the older `$wpdb` path is not required to
move; the builder is adopted per adapter, and both paths are bound in `app/`.

### Who may call it

**Repositories, and nothing else.** A repository is the one class in a module
allowed to know a table name and a column name, and the builder is the vocabulary
it writes that knowledge in. It is **never** injected into a service, a domain
object, or a controller: the point of a port such as `LeadRepositoryContract` is
that the business layer talks about leads and ids, while `where('status', 'new')`
belongs behind that port. A builder in a service constructor puts a storage
decision one call away from business logic, and the first time someone writes it
there the leak has already happened with no seam left to notice it.

| Layer                        | May resolve `QueryBuilderInterface` | Why                                              |
| ---------------------------- | ----------------------------------- | ------------------------------------------------ |
| `core/*/Infrastructure`      | **yes** — this is its only caller   | It owns the table and column names               |
| `core/*/Application`         | no                                  | Talks to ports, not tables                       |
| `core/*/Domain`              | no                                  | Knows no persistence shape at all                |
| `app/`                       | only in a provider wiring it into a repository | Controllers and services call the repository, not the builder |
| `framework/Database/`        | not through this API                | The migrator uses its own `DatabaseAdapterInterface` seam, not the builder |

Verified by grep: outside `framework/Database/Query/`, the only files naming this
interface are the three repositories that use it and the two providers in `app/`
that wire it into them. No Application service, domain object or controller
references it.

The builder is resolved through the container, so a repository receives it as a
constructor argument rather than reaching for a global:

```php
public function __construct(private readonly QueryBuilderInterface $query) {}
```

### Immutability — every modifier returns a clone

A builder is a value, not a cursor. `where()` does not change the builder it was
called on; it returns a **second** builder carrying one more clause.

```php
$base = $query->table('leads')->where('source', 'form');
$new  = $base->where('status', 'new');   // $base is untouched
$won  = $base->where('status', 'won');   // and still untouched
```

With a mutable builder that snippet is a bug: `$new` and `$won` would be the same
object holding both conditions, and whichever ran last would decide what the
first query returned. The failure is order-dependent, which is what makes it
expensive — it reproduces only on the machine that happens to interleave
differently. Immutability is what makes a half-built query safe to hold, pass to
a helper, and finish later.

### The vocabulary

Everything below is on the interface, so a repository can express its reads
without falling back to a raw statement.

| Group                | Methods                                                                                     |
| -------------------- | ------------------------------------------------------------------------------------------- |
| Start & project      | `table()`, `select()`                                                                       |
| Conditions           | `where()`, `orWhere()`, `whereGroup()`, `whereIn()`, `whereNotIn()`, `whereNull()`, `whereNotNull()` |
| Operators            | `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`, `NOT LIKE` (case-insensitive)                 |
| Joins                | `join()` (INNER), `leftJoin()`                                                              |
| Sort, page, lock     | `orderBy()`, `orderByDesc()`, `limit()`, `offset()`, `lockForUpdate()`                       |
| Read                 | `get()`, `first()`, `count()`                                                               |
| Write                | `insertGetId()`, `update()`, `delete()`                                                     |
| Transactions         | `transaction()`, `beginTransaction()`, `commit()`, `rollback()`, `inTransaction()`           |

`table()` takes a **bare** name — `leads`, never `wp_leads`. The prefix is applied
by the implementation, so a repository never writes `$wpdb->prefix . 'leads'` and
a site whose `$table_prefix` differs runs the same code.

A few of these carry a decision worth knowing about:

- **`first()` returns `null`, it does not throw.** Absence is an ordinary answer
  for a lookup; making every caller wrap a `try` around it is how a missing row
  becomes a 500. A statement that genuinely **fails** does throw, and the two are
  told apart by that.
- **`count()` ignores any `limit()` and `offset()`** and drops the projection and
  ordering. A paginated read needs the total across every page, and counting the
  current slice is how an API reports "1 of 1" on every page.
- **`update()` and `delete()` refuse to run with no condition at all.** An
  unqualified `UPDATE` overwrites every row and an unqualified `DELETE` empties
  the table; both are far more likely to be a forgotten `where()` than an intent.
- **`whereGroup()` is how you write `a AND (b OR c)`.** An `orWhere()` added after
  two `where()` calls compiles to `a AND b OR c`, which MySQL reads as
  `(a AND b) OR c`. The builder does not group for you, because it cannot know
  which grouping you meant:

  ```php
  $query->table('leads')
        ->where('status', 'new')
        ->whereGroup(fn ($q) => $q->where('name', $term)->orWhere('industry', $term));
  // WHERE `status` = %s AND (`name` = %s OR `industry` = %s)
  ```

- **`whereNotIn([])` drops the clause, `whereIn([])` is refused.** The asymmetry
  is deliberate: `IN ()` is a syntax error and an empty include list is almost
  always a filter that came out empty, while "is this column outside a set that
  turned out to be empty" has a true answer for every row. An empty exclusion
  list excludes nothing.
- **`where('col', null)` is refused.** `= NULL` is unknown in SQL, not false, so
  it matches nothing and reports no error. `whereNull()` / `whereNotNull()` say
  what was meant.
- **`lockForUpdate()` only matters inside a transaction, on InnoDB.** `FOR UPDATE`
  outside a transaction is a lock released at the end of the statement, which
  protects nothing, and MyISAM ignores it silently. Neither condition is checked —
  whether a transaction is open is the transaction manager's state and the engine
  is the table's. The schema builder emits InnoDB, so the engine half is
  satisfied by default. Only read statements honour it; a builder carrying
  `lockForUpdate()` that is then used for `update()`/`delete()` ignores it.

### Why it is safe to hand a value to

Three properties, and together they are what makes this builder usable from a
repository that takes client input.

**Bindings are deferred, never baked in.** A value passed to `where()` becomes a
placeholder and a separate argument at build time. Nothing is quoted while the
query is being assembled; `$wpdb->prepare()` fills the placeholders **once, at
execution**. This is not stylistic. `prepare()` escapes for the connection that is
open when it runs, so a builder that prepared eagerly would hand back a string
that already had its quotes doubled — a later `prepare()` over the same statement
escapes the escapes, and the value reaches the database with literal backslashes
in it. Deferring also means a half-built query can be held and finished later, and
that a builder is constructible in a context with no `$wpdb` at all.

**Identifiers are validated, because they cannot be bound.** `prepare()` quotes
*values*; a column name is structure, and a quoted column name is a syntax error.
So a column is the one place a caller's string reaches the statement
unparameterised, and it is checked against a closed pattern instead of trusted.
Permitted: letters, digits, underscores, one dot for a `table.column` qualifier,
and an `AS` alias — both halves matched by the same grammar, so neither can carry
a stray clause. A name containing a backtick or a space would otherwise close the
identifier and open a clause. Operators and sort directions are closed lists too,
and the keyword that enters the statement comes from the constant rather than from
the caller — mapped, not merely validated.

**Table qualifiers are prefixed automatically.** `companies.name` compiles to
`` `wp_companies`.`name` ``, not `` `companies`.`name` `` — the latter is MySQL
error 1054, "Unknown column", because the statement said
`FROM `wp_companies`` and never aliased it. Prefixing unconditionally is safe
here precisely because a caller can only write a real table name: there is no
alias the prefix could wrongly attach to. A column *alias* is a different thing
and is left alone.

### Usage

A real read repository combining joins, a bracketed `OR`, sorting and a
projection. Note that the builder carries no table until `table()` names one, that
each step assigns back to `$query`, and that the method returns raw rows which the
mapper turns into entities — keeping that conversion in the repository is what
lets one builder serve every table in the project.

```php
namespace Core\Deal\Infrastructure\Repositories;

use Core\Deal\Application\Contracts\DealQueryContract;
use Core\Deal\Domain\ReadModels\DealProjectionDTO;
use Core\Deal\Domain\ValueObjects\DealFilter;
use Core\Deal\Infrastructure\Mappers\DealEntityMapper;
use Core\Shared\Domain\DataObjects\PaginatedResult;
use Framework\Database\Query\QueryBuilderInterface;

final class DealReadRepository implements DealQueryContract
{
    private const TABLE = 'deals';

    /**
     * Columns the read model is built from, in mapper order. Explicit rather than
     * `SELECT *`, so the projection is a promise: adding a column to the table
     * does not silently widen every row.
     *
     * @var list<string>
     */
    private const COLUMNS = ['id', 'company_id', 'title', 'amount', 'currency', 'deal_stage_id', 'created_at'];

    public function __construct(
        private readonly QueryBuilderInterface $query,
        private readonly DealEntityMapper $mapper,
    ) {}

    /**
     * One page of deals, newest first, each carrying its company and stage name.
     */
    public function getFilteredDeals(DealFilter $filter, int $page, int $perPage): PaginatedResult
    {
        $total = $this->applyFilter($filter)->count();

        $rows = $this->applyFilter($filter)
            ->select(
                'deals.id',
                'deals.title',
                'deals.amount',
                'deals.currency',
                'companies.name AS company_name',
                'deal_stages.name AS stage_name'
            )
            // INNER JOIN: a deal with no company is not listed.
            ->join('companies', 'deals.company_id', '=', 'companies.id')
            // LEFT JOIN: a deal whose stage row is gone is still listed, without
            // its stage, rather than disappearing from the list.
            ->leftJoin('deal_stages', 'deals.deal_stage_id', '=', 'deal_stages.id')
            ->whereIn('deals.deal_stage_id', $filter->stageIds)
            // The brackets are the point: without whereGroup() this would compile
            // to `... AND `title` LIKE %s OR `companies`.`name` LIKE %s`, which
            // MySQL reads as `(stage IN ...) OR (company matches)` and returns
            // rows the stage filter excluded.
            ->whereGroup(fn (QueryBuilderInterface $q) => $q
                ->where('deals.title', '%' . $filter->escapedSearch() . '%', 'LIKE')
                ->orWhere('companies.name', '%' . $filter->escapedSearch() . '%', 'LIKE'))
            ->orderByDesc('deals.created_at')
            // `id` tie-breaks equal timestamps; without it a row can appear on two
            // pages while another is skipped.
            ->orderByDesc('deals.id')
            ->limit($perPage)
            ->offset(($page - 1) * $perPage)
            ->get();

        return PaginatedResult::fromSlice(
            array_map(fn (array $row): DealProjectionDTO => $this->mapper->toProjection($row), $rows),
            $total,
            $perPage,
            $page
        );
    }

    /**
     * The builder carrying a filter's conditions, and nothing else.
     *
     * Both the count and the page are built from this, so the total and the slice
     * cannot describe different filters. A blank search adds no condition at all
     * rather than a `1 = 1` sentinel.
     */
    private function applyFilter(DealFilter $filter): QueryBuilderInterface
    {
        $query = $this->query->table(self::TABLE);

        if ($filter->currency !== null) {
            $query = $query->where('currency', $filter->currency);
        }

        if ($filter->ownerId !== null) {
            $query = $query->where('assigned_to', $filter->ownerId);
        }

        return $query;
    }
}
```

A write, showing the row lock that makes read-then-write safe. The lock, the read,
the decision and the write all happen inside one
`TransactionManagerContract::run()` boundary — see
[Mandatory transaction management](#mandatory-transaction-management-protocol).
This is `findByIdForUpdate()` from `WPFluentCompanyWriteRepository`, which is the
only read a write port is allowed to carry:

```php
$row = $this->query
    ->table(self::TABLE_SUFFIX)
    ->select(...self::COLUMNS)
    ->where('id', $companyId)
    ->lockForUpdate()
    ->first();
```

### Transactions

The builder owns a nesting counter and exposes `transaction(callable)`,
`beginTransaction()`, `commit()`, `rollback()` and `inTransaction()`.

**An inner `transaction()` joins, it does not open a second one.** MySQL has no
nested transactions: a second `START TRANSACTION` **implicitly commits** the one
already open, so the nested write would be committed before the outer frame
decided whether to. That is precisely the half-written state a boundary exists to
prevent. The implementation counts depth and issues `BEGIN` / `COMMIT` /
`ROLLBACK` only at the outermost frame.

Prefer `transaction()` over a manual `beginTransaction()` / `commit()` pair: a
manual pair can be left open by an early return or a throw, and a transaction left
open holds locks until the connection ends.

For repositories, the caller is normally not the builder at all — a Command
Service opens the boundary through `TransactionManagerContract::run()`, which is
the project-wide rule. `WpTransactionManager` and the builder state the same
nesting guarantee, so a repository that opens one inside a service's boundary
joins it rather than committing the service's work early.

### Errors

| Exception                                    | Raised when                                                                       |
| -------------------------------------------- | --------------------------------------------------------------------------------- |
| `Framework\Database\Query\Exceptions\QueryException` | The statement failed, no table was named, or a call was refused as unsafe |
| `…\Exceptions\UniqueConstraintException`     | A unique index rejected the row — a subtype of `QueryException`                    |

`UniqueConstraintException` is detected by **MySQL error number 1062**, not by
matching the message: the number is part of the protocol, while the message is
translated and reworded between versions. It is caught in the **repository**,
which rethrows the module's own conflict exception —
`CompanyDuplicateException`, `LeadDuplicateException` — and that is what the
Application Service catches. No `Framework\*` exception is imported, caught, or
type-hinted in a service.

```php
// Core\Company\Infrastructure\Repositories\WPFluentCompanyWriteRepository
try {
    return $this->query->table(self::TABLE_SUFFIX)->insertGetId($values);
} catch (UniqueConstraintException $exception) {
    throw new CompanyDuplicateException();
}
```

The refusals above (`= NULL`, an empty `whereIn()`, an unsupported operator, a
non-positive `limit()`, an unqualified `update()`/`delete()`) all raise
`QueryException` **when the call is made**, not when the query runs. A repository
that builds a column list from configuration finds out at build time, with the
offending name in the message, rather than at 3am against a live database.

### What it does not do

- **No relationships, no eager loading.** A join is an explicit `join()` call; the
  builder has no idea two tables are related.
- **No `having()`, no subqueries, no `union()`.** A predicate needing them is a
  sign the read belongs in its own projection rather than in a general-purpose
  builder.
- **No migrations.** Schema changes go through `Schema::` — see
  [Database Migrations](#database-migrations).

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

| Kind                 | Files                                                                                                                                         | Example                                                  |
| -------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| **Static structure** | Renamed entry file, `composer.json` name + PSR-4 maps, every `App\` / `Core\` namespace in `app/`, `core/`, `config/`, `routes/` and `tests/` | `App\` → `LeadBridge\App\`, `Core\` → `LeadBridge\Core\` |
| **Config fallbacks** | The second argument of every `env()` call in `config/app.php`                                                                                 | `env('APP_NAME', 'Lead Bridge')`                         |
| **Dotenv**           | Identity keys in `.env.example`, and in `.env` when it exists                                                                                 | `APP_PREFIX="lead_bridge_"`                              |

`framework/` is never rewritten. Its `Framework\` namespace and its fallback
defaults are the library's own identity — the script prints what it left there so
the boundary is visible rather than mysterious. `.env` is rewritten when present
so a project that has already set up its environment does not keep naming the old
identity, which would win over the config fallbacks at runtime. The value the
rebrand writes for `APP_NAMESPACE` is single-quoted on purpose: phpdotenv reads a
backslash in a double-quoted value as the start of an escape sequence and refuses
to parse `\A`.

Two things are deliberately left alone even though they are yours:

- **`tests/Integration/RebranderTest.php`** holds fixtures that _describe_ the
  starter's identity — they write `App\` and `Core\` into a scratch tree and
  assert what moved. Rewriting the file would move the fixture and the assertion
  independently and leave the rebranded project with a red suite.
- **Path segments under `Tests\`.** `tests/Integration/App/Repositories/` declares
  `Tests\Integration\App\Repositories`, where `App` is a directory name rather
  than the application layer. Only the `use App\...` import in that file moves.

### Identifiers

| Identifier      | Default              | Example           | Derived from        |
| --------------- | -------------------- | ----------------- | ------------------- |
| Plugin name     | `WP Mini Laravel`    | `Lead Bridge`     | `--name`            |
| Slug / folder   | `wp-mini-laravel`    | `lead-bridge`     | `--slug`            |
| Constant prefix | `WP_MINI_LARAVEL_`   | `LEAD_BRIDGE_`    | `SNAKE(namespace)_` |
| Option prefix   | `wp_mini_laravel_`   | `lead_bridge_`    | `SNAKE(slug)_`      |
| CLI prefix      | `wp_mini`            | `leadbridge`      | `LOWER(namespace)`  |
| REST namespace  | `wp-mini-laravel/v1` | `lead-bridge/v1`  | `slug/v1`           |
| App namespace   | `App`                | `LeadBridge\App`  | `namespace\App`     |
| Core namespace  | `Core`               | `LeadBridge\Core` | `namespace\Core`    |

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

### UNIT TESTING POLICY (TEMPORARILY PAUSED)

- NEVER write, generate, scaffold, or require unit/feature/integration tests. NEVER create files under `tests/`. NEVER run test suites unless the prompt asks.
- NEVER load or follow `07-testing.txt` while paused, unless the prompt asks for tests. Do not list or claim tests in any Definition of Done or checklist.
- An explicit prompt instruction ("write a test for X") overrides the pause for that request only.
- Architecture rules are unchanged. Keep code testable (ports behind `...Contract`, pure Entities, no framework in Core).
- Only the developer re-enables testing, explicitly. Then load 07 and remove this section.

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

| Directory                | What it holds                                                                  |
| ------------------------ | ------------------------------------------------------------------------------ |
| `tests/Unit/`            | No infrastructure — pure logic against the stubs.                              |
| `tests/Integration/`     | Adapters that really issue SQL, against in-memory doubles.                     |
| `tests/Support/`         | The doubles: `FakeWpdb`, `WpStub`, `MigrationWorkspace`, recording migrations. |
| `tests/Fixtures/config/` | A rebranded config, for tests that must prove a value is _derived_.            |
| `tests/manual/`          | Runnable probes for behaviour that cannot be reached from PHPUnit.             |

`phpunit.xml` sets `failOnWarning`, `failOnNotice` and `failOnDeprecation`, so a
sloppy test is a failing test rather than a footnote.

Anything about real HTTP behaviour should be verified against a live install —
a stubbed test for it would pass for the wrong reason. That is what
`tests/manual/verify-http-lifecycle.php` is for.

---

## Configuration

All keys live in `config/app.php` and are read through `config()`:

| Key                  | Env variable     | Default                   |
| -------------------- | ---------------- | ------------------------- |
| `app.name`           | `APP_NAME`       | `WP Mini Laravel`         |
| `app.env`            | `APP_ENV`        | `production`              |
| `app.prefix`         | `APP_PREFIX`     | `wp_mini_laravel_`        |
| `app.namespace`      | `APP_NAMESPACE`  | `App`                     |
| `app.views`          | `APP_VIEWS_PATH` | `views`                   |
| `app.cli_prefix`     | `CLI_PREFIX`     | `wp_mini`                 |
| `app.rest_namespace` | `REST_NAMESPACE` | `wp-mini-laravel/v1`      |
| `app.providers`      | —                | the service provider list |
| `app.debug`          | `APP_DEBUG`      | `false`                   |

`app.debug` governs one thing: whether a **server fault** the handler had to wrap
shows its real message to the client. It does not affect a deliberately raised
`HttpException`, whose message is always shown — see
[Exception Handling](#exception-handling). It defaults to `false`, because a
framework that leaks stack detail by default leaks it on every install that never
got round to reading this table.

**Never call `env()` outside `config/`.** See [Config over env](#config-over-env).

---

## License

MIT.
