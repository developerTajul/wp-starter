# WP Mini Laravel

A Laravel-shaped architecture for WordPress plugins: a service container with
constructor injection, service providers with a register/boot lifecycle, a
configuration repository, and a REST routing layer with controllers and
middleware.

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
framework/                 The reusable machinery (a separate Composer library).
  Config/                    The configuration repository.
  Console/                   Console kernel, base Command, make:* generators.
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
```

The prefix comes from `config('app.cli_prefix')`, so two projects bundling this
framework do not collide. Generated files are written only when the target does
not already exist.

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
