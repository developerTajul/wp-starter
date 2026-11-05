# WP Mini Laravel

A Laravel-shaped architecture for WordPress plugins: a service container with
constructor injection, service providers with a register/boot lifecycle, a
configuration repository, a REST routing layer with controllers and middleware,
and a database migration engine.

This repository is a **starter project**, not a plugin you install as-is. Clone
it, rebrand it, and build your own plugin inside it — see
[Rebranding this plugin](#rebranding-this-plugin) before you write any code.

The plugin itself is a `wordpress-plugin` Composer package; the reusable
machinery lives in the sibling `framework/` library, which projects depend on
through a path repository.

## Requirements

- PHP 8.2+
- WordPress 6.0+

## Installation

```bash
composer install
```

`vendor/` is not committed. Without it the plugin shows an admin notice rather
than fataling on an uncaught `Error`.

## Layout

```
wp-mini-laravel.php        Entry file. Binds path.base, loads config, boots.
app/                       Your application.
  Console/Kernel.php         The command registry.
  Console/Command.php        Base class for your own commands.
  Http/Controllers/          Generated controllers land here.
  Providers/                 Your service providers.
config/app.php             Configuration values.
routes/api.php             REST route definitions.
database/migrations/       Versioned schema changes.
framework/                 The reusable machinery (a separate Composer library).
  Config/                    The configuration repository.
  Console/                   Console kernel, base Command, make:* generators.
  Database/                  Migrator, Migration base class, migrate:* commands.
  Routing/                   Router, routes, controllers, middleware.
  Support/helpers.php        app(), config(), base_path(), app_path()...
stubs/                     Templates the make:* commands render.
tests/                     PHPUnit suite (WordPress is stubbed, not loaded).
core/                      Your own PSR-4 namespace (Core\), currently empty.
```

## Request lifecycle

Understanding the order explains most of the framework's constraints.

1. **Plugin load.** The entry file binds `path.base` into the container and loads
   `config/`. It then bootstraps `App\Kernel`, which calls `register()` on every
   provider listed in `config('app.providers')`.
   - `register()` runs *now*, while other plugins may still be loading. It must
     only declare bindings — never `add_action`, never resolve a service.
2. **`plugins_loaded`.** The entry file's hook calls `app()->boot()`, which calls
   `boot()` on each provider. This is where hooks are registered and services
   resolved.
3. **`rest_api_init`.** `RouteServiceProvider` loads `routes/api.php`, collects
   the routes, and hands them to WordPress.

The register/boot split is why a provider may depend on a binding declared by a
provider that is listed *after* it.

## Dependency injection

Never `new` a service. Type-hint it, and the container builds it:

```php
final class LeadRepository
{
    public function __construct(private readonly WpdbGateway $gateway) {}
}

// Anywhere the container resolves LeadRepository, the gateway is injected.
```

Declare bindings in a provider's `register()`:

```php
public function register(): void
{
    $this->container->singleton(LeadRepository::class);
    $this->container->bind(LeadRepositoryInterface::class, LeadRepository::class);
}
```

An interface has no implementation of its own, so it needs an explicit binding
before anything depending on it can be constructed.

## Routing

Routes live in `routes/api.php`, which receives a fluent registrar:

```php
return function (RouteRegistrar $router): void {
    $router->get('ping', static fn (): array => ['pong' => true]);

    $router->group(
        ['prefix' => 'leads', 'middleware' => [RequireCapability::class]],
        function (RouteRegistrar $router): void {
            $router->get('', LeadController::class, 'index');
            $router->post('', LeadController::class, 'store');
            $router->get('{id:\d+}', LeadController::class, 'show');
        }
    );
};
```

Paths reach the controller's parameters by name, and the `{id:\d+}` constraint
means WordPress rejects a non-numeric id with a 404 before the controller runs.

Every route is reached under `config('app.rest_namespace')`, by default
`/wp-json/wp-mini-laravel/v1/`.

### Controllers

A controller extends `Framework\Routing\Controller` and may declare its own
routes with attributes:

```php
final class LeadController extends Controller
{
    public function __construct(private readonly LeadRepository $leads) {}

    #[Route('GET', 'leads')]
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond($this->leads->all());
    }

    #[Route('DELETE', 'leads/{id:\d+}', middleware: [RequireCapability::class])]
    public function destroy(WP_REST_Request $request): WP_REST_Response
    {
        $this->leads->delete((int) $request->get_param('id'));

        return $this->noContent();
    }
}
```

Mounting it with `(new LeadController())->registerRoutes($router)` inherits the
enclosing group's prefix and middleware, so a controller mounted inside a
protected group stays protected.

### Middleware

Middleware runs before the handler and returns a `WP_Error` to reject:

```php
final class RequireCapability implements Middleware
{
    public function __construct(private readonly string $capability = 'manage_options') {}

    public function handle(WP_REST_Request $request): ?WP_Error
    {
        return current_user_can($this->capability) ? null : new WP_Error(/* ... */);
    }
}
```

The first middleware to return an error wins, so a group's broad check runs
before a route's narrower one. Middleware is resolved from the container, so it
can declare constructor dependencies too.

## Console

Commands are registered in `app/Console/Kernel.php` and resolved from the
container, so they can declare dependencies.

```bash
wp wp_mini make:provider LeadProvider
wp wp_mini make:controller LeadController
wp wp_mini make:route
wp wp_mini make:migration create_leads_table
```

The prefix comes from `config('app.cli_prefix')`, so two projects bundling this
framework do not collide. Generated files are written only when the target does
not already exist.

## Database migrations

Migrations are versioned schema changes under `database/migrations/`, tracked in
a `{prefix}plugin_migrations` table so each one runs exactly once.

### Commands

```bash
wp wp_mini make:migration create_leads_table   # scaffold
wp wp_mini migrate                             # apply everything pending
wp wp_mini migrate:status                      # what has run, and when
wp wp_mini migrate:rollback                    # undo the last batch
wp wp_mini migrate:rollback --batch=2          # undo a specific batch
```

**`make:migration <name>`** writes `<timestamp>_<name>.php` into
`database/migrations/` and does not overwrite an existing file. The name is
normalized, so `create_leads_table`, `createLeadsTable` and `Create Leads Table`
all produce the same file. The timestamp is minted from `date('Y_m_d_His')`.

**`migrate`** applies every pending migration oldest-first as **one new batch**,
printing each as it runs. It prints `Nothing to migrate.` when the database is
already up to date. On failure it reports how many migrations stopped short, since
the tracking table only records what completed — see
[When a migration fails](#when-a-migration-fails).

**`migrate:status`** prints one row per discovered migration — `Ran?`
(`Yes`/`No`), filename, batch (or `-`), and class — followed by a total and a
pending count. Recorded migrations whose files are gone are reported separately as
a warning, because that is the one state `migrate` cannot repair on its own.

**`migrate:rollback`** reverses the most recent batch newest-first, printing each
as it goes, and warns when there is nothing to roll back. `--batch=<n>` targets a
specific one; the value must be a positive integer, so `--batch=all` and
`--batch=` are rejected rather than silently targeting batch 0. A batch that
failed *partway* has recorded migrations, so its number from `migrate:status`
does reverse them — but a batch that failed before recording anything cannot be
rolled back at all. See
[A failed batch burns its number](#a-failed-batch-burns-its-number).

`wp wp_mini` is `config('app.cli_prefix')` — see
[Rebranding this plugin](#rebranding-this-plugin) for changing it.

### Writing one

`wp wp_mini make:migration create_leads_table` renders this, and it is the shape
to copy by hand if you are not using the generator:

```php
<?php

namespace App\Database\Migrations;

use Framework\Database\Migration;

final class CreateLeadsTable extends Migration
{
    public function up(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'leads';
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(sprintf(
            'CREATE TABLE %s (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                email varchar(190) NOT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id)
            ) %s;',
            $table,
            $charset
        ));
    }

    public function down(): void
    {
        global $wpdb;

        $wpdb->query(sprintf('DROP TABLE IF EXISTS `%s`', $wpdb->prefix . 'leads'));
    }
}
```

`up()` and `down()` are mirror images: if `up()` creates, `down()` drops. There is
no automatic reversal — a `down()` that does nothing leaves the schema changed
while the tracking table forgets the migration, and re-running `migrate` will
then fail.

The class extends `Framework\Database\Migration`, whose two methods are both
abstract, so a migration that implements only `up()` is a fatal error rather than
a silent no-op. The namespace follows `config('app.namespace')`, and the class
name is derived from the filename — see
[Filenames and class names](#filenames-and-class-names).

`dbDelta()` is what makes the migration re-runnable: it diffs the definition
against the live table and issues only the `ALTER`s needed to reach it. It needs
`wp-admin/includes/upgrade.php`, which is **not** loaded under WP-CLI's default
bootstrap, hence the explicit `require_once`. Two rules are easy to get wrong and
produce a table that silently does not match:

`dbDelta()` compares that definition against the live table and issues only the
`ALTER`s needed, so a migration written this way is safe to re-run. It needs
`upgrade.php`, which WP-CLI does not load by default.

Migrations are resolved from the container, so one may take a constructor
dependency instead of reaching for `global $wpdb`.

### Filenames and class names

The filename determines the class name: the timestamp is stripped and the rest is
converted to StudlyCase.

| File | Class |
| --- | --- |
| `2026_11_05_100000_create_leads_table.php` | `CreateLeadsTable` |
| `20261105100000_create_leads_table.php` | `CreateLeadsTable` |

Renaming one without the other makes `migrate` report that the file does not
declare the class its name promises. A `.php` file in the directory that does not
follow the convention is skipped rather than treated as a migration.

### Batches

Every `migrate` run allocates one new batch number and stamps everything it
applies with it, so `migrate:rollback` undoes exactly the last run rather than
guessing from timestamps. Migrations run oldest-first within a batch and reverse
newest-first.

### When a migration fails

A failure **stops the batch**. Migrations that already completed stay recorded;
the failed one and everything after it stay pending, and the original exception is
reported. Re-running after fixing the file picks up where it stopped.

`migrate` tells you how far the batch got by naming what it recorded:

```
ERROR   Migration [2026_11_05_110000_create_leads_table] failed to up: ...
WARN    Recorded 1 of 3 migration(s); 2 stayed pending and will be retried.
```

Count that message from the tracking table, not from the `Migrating:` lines —
one of those is printed for a migration that then failed, so counting them
over-reports by one.

The tracking table therefore never claims a migration that did not finish. Note
that MySQL commits DDL implicitly, so a migration is *not* atomic — a migration
that made two changes and failed between them leaves the first in place. Write
`up()` so the first statement is the one that can fail, or make it idempotent.

#### A failed batch burns its number

A batch takes its number before its first migration runs, so a batch that fails
immediately has consumed a number with **nothing recorded under it**. When that
happens `migrate` says so:

```
WARN    This batch recorded nothing, so it cannot be rolled back.
        migrate:rollback targets the previous batch instead.
```

This is the case to be careful in. `migrate:rollback` reverses the newest batch
with *recorded migrations*, so after an empty failed batch it reverses the
**previous** one — the last run that actually did something. That is deliberate: a
batch with no rows has nothing to undo, and rolling back "nothing" silently would
be worse. But it means a failed batch is not a rollback target, and `--batch=<n>`
cannot reach it either.

To recover, fix the migration and run `migrate` again; the failed migration and
everything after it are still pending and will be retried in a fresh batch.

### Where the tracking table lives

It is `$wpdb->prefix . 'plugin_migrations'` — the WordPress table prefix, not
`config('app.prefix')` — with three columns: `id`, `migration` (the filename
without `.php`), `batch`. It is created lazily by any command that needs it,
including `migrate:status`, so there is nothing to install first.

`migration` carries a UNIQUE key, so the same migration cannot be recorded twice
even if two processes race. `batch` is indexed because rollback reads it by value.
A missing `batch` column is added automatically, which is how a table created by
an earlier version of this framework is brought forward.

Batch numbers are drawn from a WordPress option high-water mark rather than from
`MAX(batch)`. Without it, migrate → rollback → migrate would reissue batch 1 for a
different set of migrations, and a saved-off `--batch=1` would silently reverse
the wrong ones.

## Rebranding this plugin

This repository is a starter: you clone it, give it your own identity, and build
your plugin inside it. Two identities are in play and only one of them is yours.

| Layers | Namespace | Whose | Rebrand? |
| --- | --- | --- | --- |
| `app/`, `core/` | `App\`, `Core\` | Yours | **Yes** |
| `framework/` | `Framework\` | The library's | **No** |

`framework/` is a separate Composer package (`devlinkstudios/wp-mini-laravel-framework`)
consumed through a path repository and symlinked into `vendor/`. Its `Framework\`
namespace is that package's — renaming it here forks the library, and every future
framework update becomes a merge. Rebrand your own code, leave the library's alone.

If you genuinely intend to fork the library, do it in its own repository first,
publish it under your vendor name, then depend on your fork. That is a different
job from rebranding a project, and this checklist will not do it correctly.

### 1. Decide your identifiers

Pick these before touching a file; the checklist below assumes they are settled.

| Identifier | Default | Example | Where it lives |
| --- | --- | --- | --- |
| Plugin name | `WP Mini Laravel` | `Lead Bridge` | Plugin header, config, admin notice |
| Slug / folder | `wp-mini-laravel` | `lead-bridge` | Entry file, `composer.json` |
| Composer vendor | `devlinkstudios` | `acme` | Both `composer.json` files |
| Constant prefix | `WP_MINI_LARAVEL_` | `LEAD_BRIDGE_` | Entry file |
| Option prefix | `wp_mini_laravel_` | `lead_bridge_` | `config/app.php` |
| Text domain | `wp-mini-laravel` | `lead-bridge` | All `__()` calls |
| CLI prefix | `wp_mini` | `leadbridge` | `config/app.php` |
| REST namespace | `wp-mini-laravel/v1` | `lead-bridge/v1` | `config/app.php` |
| App namespace | `App` | `LeadBridge` | `config/app.php` + `composer.json` |
| Core namespace | `Core` | `LeadBridge\Core` | `composer.json` |

The four names that must be globally unique — **option prefix**, **CLI prefix**,
**REST namespace** and **constant prefix** — are the ones that break something
when two plugins share them. The rest are cosmetic.

### 2. Replace, in this order

Order matters: several steps depend on an earlier one, and the last step is what
makes the rest take effect.

1. **Move the directory** `wp-content/plugins/wp-mini-laravel/` to your slug, and
   rename the entry file `wp-mini-laravel.php` to `<your-slug>.php`. Nothing
   references either name from inside the code, so this is safe first.

2. **`composer.json`** — set `name` to `<vendor>/<your-slug>`, rename the
   `devlinkstudios/wp-mini-laravel-framework` requirement to
   `<vendor>/wp-mini-laravel-framework` (the vendor half only), and repoint the
   `App\` / `Core\` PSR-4 entries at your namespaces.

3. **`framework/composer.json`** — set `name` to `<vendor>/wp-mini-laravel-framework`.
   Leave its `Framework\` PSR-4 entry exactly as it is.

4. **The entry file** (`<your-slug>.php`) — the plugin header (`Plugin Name`,
   `Description`, `Author`, `Text Domain`), the constant
   `WP_MINI_LARAVEL_BOOTED` (both the `defined()` check and the `define()`), and
   the two `WP Mini Laravel:` strings in the admin notice.

5. **`config/app.php`** — `name`, `prefix`, `cli_prefix`, `rest_namespace`, and
   `namespace` (the `App` default; see step 9).

6. **Namespaced code** — rename the `App\` namespace in `app/Kernel.php`,
   `app/Providers/AppServiceProvider.php`, `app/Providers/RouteServiceProvider.php`,
   `app/Console/Kernel.php` and `app/Console/Command.php`. `App\Console\Kernel`
   is looked up by its literal name in `app/Kernel.php`, so both sides must agree.
   `app/Kernel.php` itself is referenced as `\App\Kernel` from the entry file.

7. **Text domain** — `wp-mini-laravel` appears in four `__()` calls:
   `framework/Routing/Router.php` (×2), `framework/Routing/Middleware/RequireCapability.php`
   and `framework/Routing/Middleware/RequireLogin.php`.

8. **`framework/Routing/Router.php`** also carries four `WP_Error` codes strings
   prefixed `wp_mini_laravel_` (`invalid_request`, `no_handler`,
   `middleware_failed`, `middleware_contract`). These are part of your plugin's
   public error surface, so rename them if you are renaming everything else.

9. **`core/`** — currently empty apart from `.gitkeep`. If you rename the `Core\`
   namespace, update the `Core\` PSR-4 entry in step 2; the directory name and
   the namespace do not have to match.

10. **`composer update && composer dump-autoload`** — required after any namespace
    or package-name change. Nothing above takes effect until this runs, and
    getting it wrong fails *silently* (see the warning below).

Low-priority cosmetics, safe to leave: the `WP Mini Laravel:` prefixes in
`framework/Routing/RouteServiceProvider.php`'s two `error_log()` calls, and the
`wp_mini` fallbacks in `config('app.cli_prefix', 'wp_mini')` — those defaults are
only reached if your config is missing the key, which the checklist above ensures
it is not.

### 3. What breaks quietly

Every one of these fails without an error message, which is why they are listed
together rather than left to discovery.

- **A mismatched PSR-4 namespace does not fail.** Change `"App\\": "app/"` to
  point at a namespace the classes do not declare, and `composer dump-autoload`
  still reports success. The classes simply stop resolving — `App\Kernel` becomes
  unloadable, and your plugin boots nothing. Composer maps prefixes to directories;
  it does not check that the files agree.

- **The option prefix is data, not a label.** `config('app.prefix')` prefixes
  stored option names. Changing it on a live site orphans every existing row:
  settings do not move, they become invisible. Safe before your first deploy,
  a migration afterwards.

- **The migration tracking table is named by the `$wpdb` prefix, not by yours.**
  It is `{wpdb->prefix}plugin_migrations`, so rebranding does not orphan applied
  migrations. Your migrations themselves live under `database/migrations/` and are
  unaffected — but any table they create still carries `$wpdb->prefix`, and those
  names are only yours to choose, never to rename after the fact.

- **A changed CLI prefix is a breaking change for every script you have written.**
  `wp wp_mini migrate` becomes whatever you set. Nothing warns you; the old
  command simply stops existing.

- **A stale `composer.lock` after a package rename gives a confusing error.** If
  step 2 changes the framework requirement but you have not run `composer update`,
  Composer reports the old package as missing rather than as renamed.

### 4. Verify the rebrand

```bash
# 1. Nothing default-named survives outside the framework library's own API.
grep -rn --exclude-dir=vendor --exclude-dir=.git -iE "mini[-_ ]?laravel" . \
  | grep -v "^./README.md"

# 2. The suite still passes after the autoloader is rebuilt.
composer dump-autoload && composer test

# 3. The plugin actually boots — this is the check that catches a broken
#    namespace, and the test that would fail if App\ stopped resolving.
composer test -- --filter PluginBootTest
```

The first command should return only the handful of deliberate cosmetic strings
from the end of step 2. If it returns nothing at all, you renamed the framework's
namespace too — re-read the table at the top of this section.

## Testing

```bash
composer test            # ./vendor/bin/phpunit
composer test:coverage   # requires xdebug or pcov
```

The suite does not load WordPress. `tests/bootstrap.php` installs a stub layer
for the WordPress functions and classes the framework touches, so the whole
framework is testable without a WordPress install. Anything about real HTTP
behaviour should be verified against a live install.

`app()` caches the container in a function-level static, so a test needing a
genuinely fresh container must use `#[RunInSeparateProcess]`.

## Configuration

`config/app.php` holds the plugin's values; read them with `config('app.name')`.
The `config()` helper is read-only — a missing key returns the default rather
than writing anything back.

Values worth knowing:

| Key | Purpose |
| --- | --- |
| `namespace` | Root namespace for generated classes (`App`). |
| `cli_prefix` | WP-CLI command namespace (`wp_mini`). |
| `prefix` | Prefix for options, transients and meta keys. |
| `rest_namespace` | REST namespace routes are published under. |
| `providers` | The service providers bootstrapped at load. |

## License

MIT
