# WP Mini Laravel

A Laravel-shaped architecture for WordPress plugins: a service container with
constructor injection, service providers with a `register`/`boot` lifecycle, a
configuration repository, dual routing (admin screens **and** a headless REST
API), a validation and resource layer, a database migration engine, and a
one-command rebrander.

This repository is a **starter project**, not a plugin you install as-is. Clone
it, rebrand it in one command, and build your plugin inside it — see
[Rebranding](#rebranding) before you write any code.

---

## Requirements

- PHP 8.2+
- WordPress 6.0+
- Composer

## Installation

```bash
composer install
```

`vendor/` is not committed. Without it, the plugin shows an admin notice rather
than fataling on an uncaught `Error`.

Then copy the environment template and fill in your values:

```bash
cp .env.example .env
```

---

## The Three-Zone Architecture

The tree is split into three zones with a strict rule about which may depend on
which. Getting this boundary right is what keeps a project upgradeable.

| Zone | Namespace | Whose | Rebrand? | May depend on |
| --- | --- | --- | --- | --- |
| `framework/` | `Framework\` | The library's | **No** | nothing above it |
| `app/` | `App\` | Yours | **Yes** | `Framework\`, `Core\` |
| `core/` | `Core\` | Yours | **Yes** | `Framework\` (never `App\`) |

### `framework/` — the reusable machinery

A separate Composer package (`devlinkstudios/wp-mini-laravel-framework`)
consumed through a path repository and symlinked into `vendor/`. It carries the
container wiring, config repository, routing, HTTP layer, validation, console
and migrations.

**This zone is read-only during a rebrand.** Its `Framework\` namespace belongs
to the library; renaming it here forks the library, and every future framework
update becomes a merge. If you genuinely intend to fork, do it in its own
repository and depend on your fork.

### `app/` — your application

Everything that is specific to *this* plugin: controllers, middleware,
resources, providers, console commands, and the `App\Kernel` that registers
them. This is where most of your work happens.

### `core/` — your domain

A namespace for framework-agnostic domain logic — entities, value objects,
service classes and their contracts — that does not import WordPress or the
framework's HTTP layer. A `NoteService` in `core/` can be tested without a
WordPress install or a request.

The rule that keeps this honest: **`core/` never depends on `app/`.** Dependencies
point inward — `app/` resolves a `Core\` contract, and the provider binds it to
an adapter.

---

## Directory Structure

```
wp-mini-laravel.php           Entry file. Loads .env, binds path.base, boots the kernel.
.env / .env.example           Environment variables (see Dotenv below).
composer.json                 Dependencies, PSR-4 maps, and the `rebrand` script.

app/                          YOUR APPLICATION  (App\)
  Kernel.php                    Registers service providers.
  Console/Kernel.php            The WP-CLI command registry.
  Console/Command.php           Base class for your own commands.
  Http/Controllers/             Controllers.
    Admin/DashboardController     The admin-screen example.
    Api/LeadController            The REST example.
  Http/Middleware/              Route guards — e.g. RequireLeadRead.
  Http/Resources/               JsonResource classes — the public shape of a payload.
  Providers/                    Your service providers.
  Repositories/                 $wpdb-backed adapters for Core contracts.
  Database/                     Transaction manager.

core/                         YOUR DOMAIN  (Core\)
  Note/                         A worked example: entity, contracts, service.
  Shared/                       Result, PaginatedResult, shared contracts.

config/app.php                Configuration values, mapped from env() with fallbacks.
routes/api.php                REST route definitions.
routes/web.php                Web route definitions (admin screens, form handlers).
views/                        PHP view files, named with dots: leads.index.
database/migrations/          Versioned schema changes.

framework/                    THE LIBRARY  (Framework\) — never rebranded
  Config/                       The configuration repository.
  Console/                      Console kernel, base Command, make:* generators.
  Database/                     Migrator, Migration base class, migrate:* commands.
  Http/                         Request, responses, middleware pipeline, FormRequest.
  Routing/                      Router, routes, controllers, middleware, adapters.
  Support/                      ViewRenderer, JsonResource, and global helpers.
    helpers.php                   app(), config(), env(), base_path(), view(), e(), old()
  Validation/                   The rule engine behind FormRequest.
  bin/                          The rebrander (init-plugin.php + lib/).

stubs/                        Templates the make:* commands render.
tests/                        PHPUnit suite (WordPress is stubbed, not loaded).
```

---

## Dotenv Support

Environment variables are managed with [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv)
and loaded in the entry file immediately after Composer's autoloader:

```php
\Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
```

`safeLoad()` (not `load()`) means the plugin **does not crash** when no `.env`
file exists — a production host that supplies variables at the server level
keeps working.

### The `env()` helper

```php
env('APP_ENV', 'production');
```

It checks `$_ENV`, then `$_SERVER`, then `getenv()`, and coerces Laravel-style
string booleans:

| Written in `.env` | Resolves to |
| --- | --- |
| `true` / `false` | `bool` |
| `(true)` / `(false)` | `bool` |
| `(null)` or an empty value | `null` |
| `(empty)` | `''` |
| unset key | the second argument |

Quote any value containing a space — phpdotenv's parser rejects unquoted
whitespace: `APP_NAME="Lead Bridge"`.

### Config over env

**`env()` is only ever called inside `config/*.php`.** Nothing in `app/`,
`core/`, `routes/` or `views/` reads the environment directly. Instead, config
maps the variable once, with a fallback, and the rest of the application reads
it through the `config()` helper or an injected `Framework\Config\Config`:

```php
// config/app.php
'name' => env('APP_NAME', 'WP Mini Laravel'),

// anywhere in app/ or core/
config('app.name');
$name = $this->config->get('app.name');   // constructor-injected Config
```

This is why the plugin keeps working under PHPUnit, WP-CLI, and any context that
boots before the entry file: the fallback in `config/app.php` is a real value,
not a missing one.

---

## Routing — Dual Surfaces

The same router definition drives two completely different WordPress surfaces.
`routes/api.php` publishes a **headless REST API**; `routes/web.php` publishes
**admin screens and form handlers**.

### Web routes

```php
return function (WordPressRouterAdapter $router): void {
    $router->adminPage('my-plugin', DashboardController::class, 'index')
        ->name('dashboard')
        ->withOptions(['capability' => 'manage_options']);

    $router->adminAction('my_form', SaveController::class, 'handle');
    $router->page('my-public-page', PublicController::class, 'show');
};
```

| Helper | WordPress mechanism | Use for |
| --- | --- | --- |
| `adminPage()` | `admin_menu` | `wp-admin` screens |
| `adminAction()` | `admin_post_{action}` | form submissions |
| `page()` | `template_redirect` | front-end pages |

A screen callback must **return** a string or a `ResponseInterface` — an admin
page returns rather than exits, so WordPress can render its chrome around it.

### Headless REST API

```php
return function (RouteRegistrar $router): void {
    $router->get('ping', static fn (): array => ['pong' => true]);

    $router->get('leads', LeadController::class, 'index')
        ->middleware(RequireLeadRead::class);

    $router->get('leads/{id}', LeadController::class, 'show')
        ->middleware(RequireLeadRead::class);
};
```

These publish under `/wp-json/{config('app.rest_namespace')}/`. The demo exposes:

```
GET /wp-json/wp-mini-laravel/v1/ping         → {"pong": true}   (open)
GET /wp-json/wp-mini-laravel/v1/leads        → {"data": [...]}  (guarded)
GET /wp-json/wp-mini-laravel/v1/leads/{id}   → {"data": {...}}  (guarded)
```

> **REST routes are public by default in WordPress.** The `leads` endpoints
> carry `RequireLeadRead` because they publish contact details; without it an
> anonymous caller reads the table. `ping` is deliberately open because it
> returns nothing about anybody — the difference between the two is the point.

Middleware is registered **by class name**, with the capability set in that
class's constructor. A `Class::class . ':capability'` spelling registers a route
whose middleware cannot be resolved — denying every caller, administrators
included.

---

## WP-CLI Integration

Commands are registered in `app/Console/Kernel.php` and exposed under the prefix
from `config('app.cli_prefix')` — `wp_mini` by default.

```bash
wp wp_mini make:controller LeadController     # a controller class
wp wp_mini make:provider BillingServiceProvider
wp wp_mini make:route ReportController         # a route + controller
wp wp_mini make:migration create_leads_table
wp wp_mini migrate                             # run pending migrations
wp wp_mini migrate:rollback
wp wp_mini migrate:status
wp wp_mini route:list                          # every registered route
```

`Command` deliberately does **not** extend `WP_CLI_Command` — WP-CLI accepts a
plain callable, so extending it would couple every command to WP-CLI being
loaded merely to be *declared*, not to be run.

---

## Request Lifecycle

1. WordPress includes `wp-mini-laravel.php`.
2. The entry file loads `.env`, then binds `path.base`.
3. `config/*.php` is loaded into `Framework\Config\Config`.
4. `App\Kernel::bootstrap()` registers every provider in `config/app.php`.
5. `app()->boot()` runs on the `plugins_loaded` hook.
6. A request arrives; the routing provider picks the surface (REST, admin page,
   admin action, or front-end page) and dispatches through middleware to a
   controller.

Dependencies are resolved by **constructor injection** through the container:

```php
final class LeadController
{
    public function __construct(private readonly NoteService $notes) {}
}
```

---

## Database Migrations

Migrations live in `database/migrations/`, named `YYYY_MM_DD_HHMMSS_description.php`.
Each is one reversible step: whatever `up()` creates, `down()` must remove.

```php
use Framework\Database\Migration;
use wpdb;

final class CreateLeadsTable extends Migration
{
    // Resolved from the container, so the handle is injected rather than
    // reached for through `global $wpdb` — which is what lets it run against a
    // test database without a WordPress install.
    public function __construct(private ?wpdb $wpdb = null) {}

    public function up(): void
    {
        $table = $this->wpdb->prefix . 'leads';

        $this->wpdb->query(sprintf(
            'CREATE TABLE `%s` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `email` varchar(255) NOT NULL,
                PRIMARY KEY (`id`)
            ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $table
        ));
    }

    public function down(): void
    {
        $this->wpdb->query(
            sprintf('DROP TABLE IF EXISTS `%s`', $this->wpdb->prefix . 'leads')
        );
    }
}
```

There is deliberately **no schema-builder DSL and no `dbDelta()`**. A migration
already has the thing `dbDelta()` exists to substitute for — a record of what has
been applied — so a plain `CREATE TABLE` runs exactly once and a failure is a
failure rather than a diff that decided there was nothing to do. Migrations
resolve from the container, so a constructor dependency is declared rather than
reached for globally.

The tracking table is `{wpdb->prefix}plugin_migrations` — keyed on the
**WordPress** table prefix, not the plugin's. That means rebranding does not
orphan applied migrations. Tables your migrations create still carry
`$wpdb->prefix`, and those names are only yours to choose, never to rename after
the fact.

---

## Rebranding

The rebrander lives at **`framework/bin/init-plugin.php`** and is exposed as a
Composer script. It takes `--key=value` flags (not positional arguments):

```bash
composer rebrand -- \
  --name="Lead Bridge" \
  --slug=lead-bridge \
  --vendor=acme \
  --namespace=LeadBridge
```

`--vendor` defaults to `devlinkstudios`. Add `--dry-run` to preview every change
without writing anything:

```bash
composer rebrand -- --dry-run \
  --name="Lead Bridge" \
  --slug=lead-bridge \
  --vendor=acme \
  --namespace=LeadBridge
```

Anything not supplied is derived from the four required values — you never have
to invent a REST namespace or a CLI prefix by hand (see the table below).

### What it rewrites — the hybrid pass

| Kind | Files | Example |
| --- | --- | --- |
| **Static structure** | Renamed entry file, `composer.json` name + PSR-4 maps, all namespaces in `app/`, `core/`, `config/`, `routes/`, `tests/` | `App\` → `LeadBridge\` |
| **Config fallbacks** | The second argument of every `env()` call in `config/app.php` | `env('APP_NAME', 'Lead Bridge')` |
| **Dotenv** | Identity keys in `.env.example` and `.env` (when present) | `APP_PREFIX="lead_bridge_"` |

`framework/` is never rewritten. Its `Framework\` namespace and its fallback
defaults are the library's own identity — the script prints what it left there
so the boundary is visible rather than mysterious.

### Identifiers

| Identifier | Default | Example | Derived from |
| --- | --- | --- | --- |
| Plugin name | `WP Mini Laravel` | `Lead Bridge` | `--name` |
| Slug / folder | `wp-mini-laravel` | `lead-bridge` | `--slug` |
| Constant prefix | `WP_MINI_LARAVEL_` | `LEAD_BRIDGE_` | `SNAKE(namespace)_` |
| Option prefix | `wp_mini_laravel_` | `lead_bridge_` | `SNAKE(slug)_` |
| CLI prefix | `wp_mini` | `leadbridge` | `LOWER(namespace)` |
| REST namespace | `wp-mini-laravel/v1` | `lead-bridge/v1` | `slug/v1` |
| App namespace | `App` | `LeadBridge` | `--namespace` |
| Core namespace | `Core` | `LeadBridge\Core` | `namespace\Core` |

The four names that must be **globally unique** — option prefix, CLI prefix,
REST namespace and constant prefix — are the ones that break something when two
plugins share them. The rest are cosmetic. The script refuses a rebrand to any
of the default values, because that changes nothing while reporting success.

### After the rebrand

```bash
composer update && composer dump-autoload   # not optional — see below
composer test
composer test -- --filter PluginBootTest
```

### What breaks quietly

- **A mismatched PSR-4 namespace does not fail.** `composer dump-autoload`
  reports success; the classes simply stop resolving and the plugin boots
  nothing. Composer maps prefixes to directories and never checks they agree.
- **The option prefix is data, not a label.** Changing it on a live site
  orphans every stored row — settings become invisible.
- **A changed CLI prefix is a breaking change** for every script you have
  written. Nothing warns you; the old command stops existing.
- **A stale `composer.lock` after a package rename** gives a confusing "package
  not found" rather than "renamed".

---

## Testing

```bash
vendor/bin/phpunit                       # the whole suite
vendor/bin/phpunit --filter PluginBootTest
composer test                            # same as vendor/bin/phpunit
composer test:coverage                   # requires xdebug or pcov
```

The suite does **not** load WordPress. `tests/bootstrap.php` installs a stub
layer for the WordPress functions and classes the framework touches, so the
whole framework is testable without a WordPress install.

| Suite | Path | What it covers |
| --- | --- | --- |
| Unit | `tests/Unit/` | No infrastructure — pure logic against stubs. |
| Integration | `tests/Integration/` | Adapters that really issue SQL, against in-memory doubles. |
| Manual | `tests/manual/` | Probes for behaviour that cannot be reached from PHPUnit. |

Anything about real HTTP behaviour should be verified against a live install —
a stubbed test for it would pass for the wrong reason.

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

**Never call `env()` outside `config/`.** See [Config over env](#config-over-env).

---

## License

MIT.
